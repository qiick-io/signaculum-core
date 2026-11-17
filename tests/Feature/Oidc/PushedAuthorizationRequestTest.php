<?php

declare(strict_types=1);

use App\Models\PushedAuthorizationRequest;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * RFC 9126 Pushed Authorization Requests (PAR).
 *
 * Clients push the authorization request to `POST /oauth/par`, receive a
 * single-use `request_uri`, then call `/oauth/authorize` with only
 * `client_id` + `request_uri`. Request Objects (`request`) stay unsupported.
 */
beforeEach(function (): void {
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * @param  array<string, string|null>  $overrides
 * @return array<string, string>
 */
function parPayload(array $overrides = []): array
{
    $parameters = array_filter([
        'response_type' => 'code',
        'client_id' => test()->client->getKey(),
        'client_secret' => test()->client->plainSecret,
        'redirect_uri' => TEST_REDIRECT_URI,
        'scope' => 'openid profile email',
        'state' => 'state-123',
        'nonce' => 'nonce-456',
        'code_challenge' => test()->pkce['challenge'],
        'code_challenge_method' => 'S256',
        ...$overrides,
    ], fn (?string $value): bool => $value !== null);

    /** @var array<string, string> $parameters */
    return $parameters;
}

test('discovery advertises the PAR endpoint and leaves require_pushed false', function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $document = $this->getJson('/.well-known/openid-configuration')->json();

    expect($document['pushed_authorization_request_endpoint'])->toBe('https://id.example.test/oauth/par')
        ->and($document['require_pushed_authorization_requests'])->toBeFalse()
        ->and($document['request_parameter_supported'])->toBeFalse()
        ->and($document['request_uri_parameter_supported'])->toBeFalse();
});

test('PAR accepts a valid pushed authorization request and returns a request_uri', function () {
    $response = $this->post('/oauth/par', parPayload())
        ->assertCreated()
        ->assertHeaderContains('Cache-Control', 'no-cache')
        ->assertHeaderContains('Cache-Control', 'no-store')
        ->assertJsonStructure(['request_uri', 'expires_in']);

    $requestUri = $response->json('request_uri');

    expect($requestUri)->toStartWith('urn:ietf:params:oauth:request_uri:')
        ->and($response->json('expires_in'))->toBe((int) config('oidc.par_expires_in'))
        ->and(PushedAuthorizationRequest::query()->where('request_uri', $requestUri)->exists())->toBeTrue();
});

test('PAR authenticates confidential clients with HTTP Basic like the token endpoint', function () {
    $payload = parPayload(['client_secret' => null]);

    $this->withBasicAuth((string) $this->client->getKey(), (string) $this->client->plainSecret)
        ->post('/oauth/par', $payload)
        ->assertCreated();
});

test('PAR authenticates public clients with client_id alone', function () {
    $publicClient = createOidcClient(isConfidential: false);
    $payload = parPayload([
        'client_id' => (string) $publicClient->getKey(),
        'client_secret' => null,
    ]);

    $this->post('/oauth/par', $payload)->assertCreated();
});

test('PAR refuses a wrong client secret as invalid_client', function () {
    $this->post('/oauth/par', parPayload(['client_secret' => 'wrong-secret']))
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_client');
});

test('PAR refuses a body client_id that does not match the authenticated client', function () {
    $otherClient = createOidcClient();

    $this->post('/oauth/par', parPayload([
        'client_id' => (string) $otherClient->getKey(),
    ]))->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_client');
});

test('PAR validates PKCE S256 before storing the request', function () {
    $this->post('/oauth/par', parPayload([
        'code_challenge' => null,
        'code_challenge_method' => null,
    ]))->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');

    expect(PushedAuthorizationRequest::query()->count())->toBe(0);
});

test('PAR validates nonce for openid before storing the request', function () {
    $this->post('/oauth/par', parPayload(['nonce' => null]))
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');

    expect(PushedAuthorizationRequest::query()->count())->toBe(0);
});

test('PAR validates the redirect_uri exactly before storing the request', function () {
    $this->post('/oauth/par', parPayload([
        'redirect_uri' => 'https://attacker.example.test/callback',
    ]))->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');

    expect(PushedAuthorizationRequest::query()->count())->toBe(0);
});

test('PAR refuses an unknown scope before storing the request', function () {
    $this->post('/oauth/par', parPayload(['scope' => 'openid admin']))
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_scope');

    expect(PushedAuthorizationRequest::query()->count())->toBe(0);
});

test('PAR refuses request_uri in the pushed body', function () {
    $this->post('/oauth/par', parPayload([
        'request_uri' => 'urn:ietf:params:oauth:request_uri:nested',
    ]))->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');
});

test('PAR refuses the OIDC request parameter in the pushed body', function () {
    $this->post('/oauth/par', parPayload([
        'request' => 'eyJhbGciOiJub25lIn0.e30.',
    ]))->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');
});

test('authorize accepts client_id and a PAR request_uri and issues a code', function () {
    $requestUri = $this->post('/oauth/par', parPayload())->json('request_uri');

    $this->actingAs(User::factory()->create());

    $query = approveAuthorization($this->client, $this->pkce['challenge'], [
        'response_type' => null,
        'redirect_uri' => null,
        'scope' => null,
        'state' => null,
        'nonce' => null,
        'code_challenge' => null,
        'code_challenge_method' => null,
        'request_uri' => $requestUri,
    ]);

    expect($query)->toHaveKey('code')
        ->and($query['state'])->toBe('state-123');
});

test('authorize ignores extra parameters when a PAR request_uri is used', function () {
    $requestUri = $this->post('/oauth/par', parPayload())->json('request_uri');

    $this->actingAs(User::factory()->create());

    $query = approveAuthorization($this->client, $this->pkce['challenge'], [
        'response_type' => null,
        'redirect_uri' => null,
        'scope' => null,
        'state' => 'attacker-state',
        'nonce' => 'attacker-nonce',
        'code_challenge' => null,
        'code_challenge_method' => null,
        'request_uri' => $requestUri,
    ]);

    expect($query['state'])->toBe('state-123')
        ->and($query)->toHaveKey('code');
});

test('authorize rejects a PAR request_uri bound to another client', function () {
    $requestUri = $this->post('/oauth/par', parPayload())->json('request_uri');
    $otherClient = createOidcClient();

    $this->actingAs(User::factory()->create());

    $response = $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $otherClient->getKey(),
        'request_uri' => $requestUri,
    ]));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->json('error'))->toBe('invalid_request')
        ->and((string) $response->headers->get('Location'))->toBe('');
});

test('authorize rejects an expired PAR request_uri', function () {
    $requestUri = $this->post('/oauth/par', parPayload())->json('request_uri');

    PushedAuthorizationRequest::query()
        ->where('request_uri', $requestUri)
        ->update(['expires_at' => Date::now()->subMinute()]);

    $this->actingAs(User::factory()->create());

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $this->client->getKey(),
        'request_uri' => $requestUri,
    ]))->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');
});

test('authorize rejects a reused PAR request_uri', function () {
    $requestUri = $this->post('/oauth/par', parPayload())->json('request_uri');

    $this->actingAs(User::factory()->create());

    approveAuthorization($this->client, $this->pkce['challenge'], [
        'response_type' => null,
        'redirect_uri' => null,
        'scope' => null,
        'state' => null,
        'nonce' => null,
        'code_challenge' => null,
        'code_challenge_method' => null,
        'request_uri' => $requestUri,
    ]);

    $this->get('/oauth/authorize?'.http_build_query([
        'client_id' => $this->client->getKey(),
        'request_uri' => $requestUri,
    ]))->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');
});

test('a non-PAR request_uri is still refused as request_uri_not_supported', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'request_uri' => 'https://client.example.test/request.jwt',
    ]));

    expect(redirectQuery($response->headers->get('Location'))['error'])->toBe('request_uri_not_supported');
});

test('request still takes precedence and stays request_not_supported alongside a PAR uri', function () {
    $requestUri = $this->post('/oauth/par', parPayload())->json('request_uri');

    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'request' => 'eyJhbGciOiJub25lIn0.e30.',
        'request_uri' => $requestUri,
    ]));

    expect(redirectQuery($response->headers->get('Location'))['error'])->toBe('request_not_supported');
});

test('the PAR endpoint is rate limited like the token endpoint', function () {
    config(['oidc.rate_limits.token_per_minute' => 1]);

    $this->post('/oauth/par', parPayload())->assertCreated();

    $this->post('/oauth/par', parPayload())->assertStatus(429);
});
