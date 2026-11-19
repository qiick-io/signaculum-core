<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\Client;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * Admin REST API `/api/v1/applications` (#180): what each endpoint refuses before what it does.
 *
 * The Admin API is reachable only by a key an instance admin issued
 * ({@see AuthenticateApiKeyMiddleware}), so the boundary tested here is the
 * scope, not an organisation: there is no cross-tenant application caller to construct.
 */
function applicationApiPayload(array $overrides = []): array
{
    return [
        'name' => 'Externa',
        'redirect_uris' => ['https://externa.test/auth/oidc/callback'],
        'is_confidential' => true,
        'is_first_party' => false,
        ...$overrides,
    ];
}

function namedOidcClient(string $name, bool $isConfidential = true): Client
{
    /** @var Client $client */
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        $name,
        ['https://'.Str::slug($name).'.test/callback'],
        $isConfidential,
    );

    return $client;
}

it('refuses every applications endpoint without a key, and changes nothing', function (): void {
    $application = namedOidcClient('Unauthenticated probe');
    $secretBefore = $application->secret;

    foreach ([
        fn () => $this->getJson('/api/v1/applications'),
        fn () => $this->getJson('/api/v1/applications/'.$application->getKey()),
        fn () => $this->postJson('/api/v1/applications', applicationApiPayload()),
        fn () => $this->postJson('/api/v1/applications/'.$application->getKey().'/rotate-secret'),
    ] as $call) {
        $call()
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
            ->assertExactJson(['message' => 'Missing API key.', 'error' => 'invalid_token']);
    }

    expect(Client::query()->where('name', 'Externa')->exists())->toBeFalse()
        ->and($application->fresh()?->secret)->toBe($secretBefore);
});

it('refuses a read key on the applications write endpoints, and changes nothing', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);
    $application = namedOidcClient('Read only probe');
    $secretBefore = $application->secret;

    $this->withToken($plain)->postJson('/api/v1/applications', applicationApiPayload())
        ->assertForbidden()
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="applications:write"')
        ->assertJsonPath('error', 'insufficient_scope');

    $this->withToken($plain)->postJson('/api/v1/applications/'.$application->getKey().'/rotate-secret')
        ->assertForbidden()
        ->assertJsonPath('scope', 'applications:write');

    expect(Client::query()->where('name', 'Externa')->exists())->toBeFalse()
        ->and($application->fresh()?->secret)->toBe($secretBefore);
});

it('refuses a key scoped to another resource on the applications read endpoints', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value, ApiKeyScopeEnum::OrganizationsWrite->value]);
    $application = namedOidcClient('Wrong resource probe');

    $this->withToken($plain)->getJson('/api/v1/applications')
        ->assertForbidden()
        ->assertJsonPath('scope', 'applications:read');

    $this->withToken($plain)->getJson('/api/v1/applications/'.$application->getKey())
        ->assertForbidden()
        ->assertJsonPath('scope', 'applications:read');
});

it('refuses a malformed create payload field by field', function (array $payload, array $invalidFields): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)->postJson('/api/v1/applications', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidFields);

    expect(Client::query()->where('name', $payload['name'] ?? 'Externa')->exists())->toBeFalse();
})->with([
    'no name' => [['redirect_uris' => ['https://a.test/cb'], 'is_confidential' => true, 'is_first_party' => false], ['name']],
    'name too long' => [[...applicationApiPayload(), 'name' => str_repeat('a', 256)], ['name']],
    'no redirect uris' => [[...applicationApiPayload(), 'redirect_uris' => []], ['redirect_uris']],
    'redirect uri not a url' => [[...applicationApiPayload(), 'redirect_uris' => ['not-a-url']], ['redirect_uris.0']],
    'redirect uris not a list' => [[...applicationApiPayload(), 'redirect_uris' => 'https://a.test/cb'], ['redirect_uris']],
    'confidential missing' => [['name' => 'Externa', 'redirect_uris' => ['https://a.test/cb'], 'is_first_party' => false], ['is_confidential']],
    'confidential not boolean' => [[...applicationApiPayload(), 'is_confidential' => 'maybe'], ['is_confidential']],
    'first party missing' => [['name' => 'Externa', 'redirect_uris' => ['https://a.test/cb'], 'is_confidential' => true], ['is_first_party']],
    'client credentials not boolean' => [[...applicationApiPayload(), 'is_client_credentials_enabled' => 'yes please'], ['is_client_credentials_enabled']],
]);

it('answers 404 for an application id that does not exist', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)->getJson('/api/v1/applications/'.Str::uuid()->toString())->assertNotFound();
    $this->withToken($plain)->postJson('/api/v1/applications/'.Str::uuid()->toString().'/rotate-secret')->assertNotFound();
});

it('lists applications with the documented resource shape', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);
    $application = namedOidcClient('Listed app');

    $this->withToken($plain)->getJson('/api/v1/applications')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $application->getKey())
        ->assertJsonPath('data.0.name', 'Listed app')
        ->assertJsonPath('data.0.is_confidential', true)
        ->assertJsonPath('data.0.is_first_party', false)
        ->assertJsonPath('data.0.is_client_credentials_enabled', false)
        ->assertJsonPath('data.0.redirect_uris', ['https://listed-app.test/callback'])
        ->assertJsonStructure(['data' => [['created_at', 'updated_at']], 'links', 'meta']);
});

it('never exposes an application secret through the list or show endpoints', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);
    $application = namedOidcClient('Secret holder');

    $list = $this->withToken($plain)->getJson('/api/v1/applications')->assertOk();
    $show = $this->withToken($plain)->getJson('/api/v1/applications/'.$application->getKey())->assertOk();

    foreach ([$list, $show] as $response) {
        expect($response->json())->not->toHaveKey('data.secret')
            ->and($response->getContent())->not->toContain('plain_text_secret')
            ->and($response->getContent())->not->toContain((string) $application->secret);
    }
});

it('clamps per_page to the configured maximum and floors it at one', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);
    config(['api.max_per_page' => 2]);

    foreach (range(1, 3) as $index) {
        namedOidcClient('Paged app '.$index);
    }

    $this->withToken($plain)->getJson('/api/v1/applications?per_page=500')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');

    $this->withToken($plain)->getJson('/api/v1/applications?per_page=0')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonCount(1, 'data');
});

it('searches and sorts the applications list and ignores an unknown sort column', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);
    namedOidcClient('Alpha portal');
    namedOidcClient('Beta portal');
    namedOidcClient('Gamma console');

    $this->withToken($plain)->getJson('/api/v1/applications?search=portal')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $ascending = $this->withToken($plain)->getJson('/api/v1/applications?sort=name&direction=asc')->assertOk();
    $descending = $this->withToken($plain)->getJson('/api/v1/applications?sort=name&direction=desc')->assertOk();

    expect($ascending->json('data.0.name'))->toBe('Alpha portal')
        ->and($descending->json('data.0.name'))->toBe('Gamma console');

    // An unsortable column falls back to created_at rather than reaching the database.
    $this->withToken($plain)->getJson('/api/v1/applications?sort=secret&direction=asc')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('creates a confidential application and returns the secret exactly once', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $response = $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload([
            'is_first_party' => true,
            'is_client_credentials_enabled' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Externa')
        ->assertJsonPath('data.is_confidential', true)
        ->assertJsonPath('data.is_first_party', true)
        ->assertJsonPath('data.is_client_credentials_enabled', true);

    $plainTextSecret = $response->json('data.plain_text_secret');
    $application = Client::query()->where('name', 'Externa')->firstOrFail();

    expect($plainTextSecret)->toBeString()->not->toBe('')
        ->and($application->secret)->not->toBe($plainTextSecret);

    // Reading the application back never repeats the secret.
    $this->withToken($plain)->getJson('/api/v1/applications/'.$application->getKey())
        ->assertOk()
        ->assertJsonMissingPath('data.plain_text_secret');
});

it('creates a public application without a secret', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload(['name' => 'Spa client', 'is_confidential' => false]))
        ->assertCreated()
        ->assertJsonPath('data.is_confidential', false)
        ->assertJsonMissingPath('data.plain_text_secret');
});

it('rotates a confidential application secret and invalidates the old one', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);
    $application = namedOidcClient('Rotating app');
    $hashedSecretBefore = $application->secret;

    $response = $this->withToken($plain)
        ->postJson('/api/v1/applications/'.$application->getKey().'/rotate-secret')
        ->assertOk()
        ->assertJsonPath('data.id', (string) $application->getKey());

    $rotatedSecret = $response->json('data.plain_text_secret');

    expect($rotatedSecret)->toBeString()->not->toBe('')
        ->and($application->fresh()?->secret)->not->toBe($hashedSecretBefore);
});
