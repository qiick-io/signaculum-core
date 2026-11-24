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
    // Shapes Laravel's `url` rule lets through and the admin form's RedirectUriRule refuses (#279).
    'redirect uri over plain http' => [[...applicationApiPayload(), 'redirect_uris' => ['http://a.test/cb']], ['redirect_uris.0']],
    'redirect uri with a fragment' => [[...applicationApiPayload(), 'redirect_uris' => ['https://a.test/cb#done']], ['redirect_uris.0']],
    'redirect uri with another scheme' => [[...applicationApiPayload(), 'redirect_uris' => ['ftp://a.test/cb']], ['redirect_uris.0']],
    'confidential missing' => [['name' => 'Externa', 'redirect_uris' => ['https://a.test/cb'], 'is_first_party' => false], ['is_confidential']],
    'confidential not boolean' => [[...applicationApiPayload(), 'is_confidential' => 'maybe'], ['is_confidential']],
    'first party missing' => [['name' => 'Externa', 'redirect_uris' => ['https://a.test/cb'], 'is_confidential' => true], ['is_first_party']],
    'client credentials not boolean' => [[...applicationApiPayload(), 'is_client_credentials_enabled' => 'yes please'], ['is_client_credentials_enabled']],
    'pushed authorization not boolean' => [[...applicationApiPayload(), 'is_pushed_authorization_required' => 'yes please'], ['is_pushed_authorization_required']],
    'device authorization not boolean' => [[...applicationApiPayload(), 'is_device_authorization_enabled' => 'yes please'], ['is_device_authorization_enabled']],
    'post-logout uris not a list' => [[...applicationApiPayload(), 'post_logout_redirect_uris' => 'https://a.test/'], ['post_logout_redirect_uris']],
    'post-logout uri over plain http' => [[...applicationApiPayload(), 'post_logout_redirect_uris' => ['http://a.test/logged-out']], ['post_logout_redirect_uris.0']],
    'post-logout uri with a fragment' => [[...applicationApiPayload(), 'post_logout_redirect_uris' => ['https://a.test/logged-out#done']], ['post_logout_redirect_uris.0']],
    'post-logout uri with a wildcard host' => [[...applicationApiPayload(), 'post_logout_redirect_uris' => ['https://*.a.test/logged-out']], ['post_logout_redirect_uris.0']],
    'post-logout uri not a string' => [[...applicationApiPayload(), 'post_logout_redirect_uris' => [['nested']]], ['post_logout_redirect_uris.0']],
]);

// The post-logout field shares the redirect URI rule, but its errors name the field the client sent.
it('names post-logout redirect uris in their validation errors', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)->postJson('/api/v1/applications', applicationApiPayload([
        'post_logout_redirect_uris' => ['https://a.test/logged-out#done'],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['post_logout_redirect_uris.0' => 'Post-logout redirect URIs must not contain a fragment.']);
});

it('creates an application with post-logout redirect uris and reads them back', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value, ApiKeyScopeEnum::ApplicationsRead->value]);

    $created = $this->withToken($plain)->postJson('/api/v1/applications', applicationApiPayload([
        'post_logout_redirect_uris' => ['https://externa.test/auth/oidc/logged-out', 'https://externa.test/'],
    ]))->assertCreated()
        ->assertJsonPath('data.post_logout_redirect_uris', ['https://externa.test/auth/oidc/logged-out', 'https://externa.test/']);

    $this->withToken($plain)->getJson('/api/v1/applications/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.post_logout_redirect_uris', ['https://externa.test/auth/oidc/logged-out', 'https://externa.test/']);

    expect(Client::query()->whereKey($created->json('data.id'))->sole()->post_logout_redirect_uris)
        ->toBe(['https://externa.test/auth/oidc/logged-out', 'https://externa.test/']);
});

it('lists an application without post-logout redirect uris as an empty list', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);
    namedOidcClient('Listed app');

    $this->withToken($plain)->getJson('/api/v1/applications')
        ->assertOk()
        ->assertJsonPath('data.0.post_logout_redirect_uris', []);
});

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
        ->assertJsonPath('data.0.is_pushed_authorization_required', false)
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

it('accepts the per-application PAR requirement on create and reads it back', function (): void {
    ['plain' => $plain] = adminApiKey([
        ApiKeyScopeEnum::ApplicationsWrite->value,
        ApiKeyScopeEnum::ApplicationsRead->value,
    ]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload([
            'name' => 'Par required app',
            'is_pushed_authorization_required' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.is_pushed_authorization_required', true);

    $application = Client::query()->where('name', 'Par required app')->firstOrFail();

    // The flag the API accepted is the flag the authorization endpoint enforces.
    expect($application->requiresPushedAuthorizationRequests())->toBeTrue();

    $this->withToken($plain)->getJson('/api/v1/applications/'.$application->getKey())
        ->assertOk()
        ->assertJsonPath('data.is_pushed_authorization_required', true);
});

it('defaults the per-application PAR requirement to off when the field is omitted', function (): void {
    ['plain' => $plain] = adminApiKey([
        ApiKeyScopeEnum::ApplicationsWrite->value,
        ApiKeyScopeEnum::ApplicationsRead->value,
    ]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload(['name' => 'Par default app']))
        ->assertCreated()
        ->assertJsonPath('data.is_pushed_authorization_required', false);

    $application = Client::query()->where('name', 'Par default app')->firstOrFail();

    expect($application->requiresPushedAuthorizationRequests())->toBeFalse();
});

/*
 * #128 gave applications a second door. A per-client setting the admin form writes
 * must mean the same thing through the Admin REST API: validated on the way in,
 * visible on the way out. Front-channel logout (#65) adds two of them.
 */
it('accepts the front-channel logout settings on create and reads them back', function (): void {
    ['plain' => $plain] = adminApiKey([
        ApiKeyScopeEnum::ApplicationsWrite->value,
        ApiKeyScopeEnum::ApplicationsRead->value,
    ]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload([
            'name' => 'Front channel app',
            'frontchannel_logout_uri' => 'https://front-channel.test/logout',
            'is_frontchannel_logout_session_required' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.frontchannel_logout_uri', 'https://front-channel.test/logout')
        ->assertJsonPath('data.is_frontchannel_logout_session_required', true);

    $application = Client::query()->where('name', 'Front channel app')->firstOrFail();

    // The URI the API accepted is the URI the logout flow will load.
    expect($application->frontchannel_logout_uri)->toBe('https://front-channel.test/logout')
        ->and($application->is_frontchannel_logout_session_required)->toBeTrue();

    $this->withToken($plain)->getJson('/api/v1/applications/'.$application->getKey())
        ->assertOk()
        ->assertJsonPath('data.frontchannel_logout_uri', 'https://front-channel.test/logout')
        ->assertJsonPath('data.is_frontchannel_logout_session_required', true);
});

it('defaults the front-channel logout settings to unset when the fields are omitted', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload(['name' => 'No front channel app']))
        ->assertCreated()
        ->assertJsonPath('data.frontchannel_logout_uri', null)
        ->assertJsonPath('data.is_frontchannel_logout_session_required', false);
});

it('refuses a front-channel logout URI the admin form would also refuse', function (string $uri): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload([
            'name' => 'Bad front channel app',
            'frontchannel_logout_uri' => $uri,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['frontchannel_logout_uri']);

    expect(Client::query()->where('name', 'Bad front channel app')->exists())->toBeFalse();
})->with([
    'plain http' => ['http://front-channel.test/logout'],
    'carries a fragment' => ['https://front-channel.test/logout#done'],
    'not a url' => ['not-a-url'],
    'carries a user name and password' => ['https://user:pass@front-channel.test/logout'],
    'carries a user name' => ['https://front-channel.test@evil.test/logout'],
]);

/*
 * Device authorization (#110) was the third field to exist on one door only: present
 * twice in StoreApplicationRequest and twice in the web ApplicationResource, absent
 * from the Admin API's validation and from its resource. An application created
 * through the API always had the device grant off, and said 201 while doing it.
 */
it('accepts the device authorization grant on create and reads it back', function (): void {
    ['plain' => $plain] = adminApiKey([
        ApiKeyScopeEnum::ApplicationsWrite->value,
        ApiKeyScopeEnum::ApplicationsRead->value,
    ]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload([
            'name' => 'Device grant app',
            'is_device_authorization_enabled' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('data.is_device_authorization_enabled', true);

    $application = Client::query()->where('name', 'Device grant app')->firstOrFail();

    // The flag the API accepted is the grant the device authorization endpoint honours.
    expect($application->hasGrantType('urn:ietf:params:oauth:grant-type:device_code'))->toBeTrue();

    $this->withToken($plain)->getJson('/api/v1/applications/'.$application->getKey())
        ->assertOk()
        ->assertJsonPath('data.is_device_authorization_enabled', true);
});

it('defaults the device authorization grant to off when the field is omitted', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/applications', applicationApiPayload(['name' => 'No device grant app']))
        ->assertCreated()
        ->assertJsonPath('data.is_device_authorization_enabled', false);

    $application = Client::query()->where('name', 'No device grant app')->firstOrFail();

    expect($application->hasGrantType('urn:ietf:params:oauth:grant-type:device_code'))->toBeFalse();
});

it('accepts loopback redirect uris over http, as the admin form does', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsWrite->value]);

    $this->withToken($plain)->postJson('/api/v1/applications', applicationApiPayload([
        'redirect_uris' => ['http://localhost:3000/callback', 'http://127.0.0.1/callback'],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.redirect_uris', ['http://localhost:3000/callback', 'http://127.0.0.1/callback']);
});
