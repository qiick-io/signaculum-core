<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'email' => 'ada@example.test',
    ]);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * Redeem a code and return the full token endpoint JSON.
 *
 * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int}
 */
function issueTokens(object $testCase): array
{
    $testCase->actingAs($testCase->user);
    $code = approveAuthorization($testCase->client, $testCase->pkce['challenge'])['code'];

    /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int} $payload */
    $payload = redeemCode($testCase->client, $code, $testCase->pkce['verifier'])
        ->assertOk()
        ->json();

    return $payload;
}

/**
 * POST /oauth/introspect as a confidential client (client_secret_post).
 *
 * @param  array<string, string>  $overrides
 */
function introspectAs(object $testCase, string $token, array $overrides = []): TestResponse
{
    return $testCase->post('/oauth/introspect', [
        'token' => $token,
        'client_id' => $testCase->client->getKey(),
        'client_secret' => $testCase->client->plainSecret,
        ...$overrides,
    ]);
}

test('discovery advertises the introspection endpoint', function () {
    $this->get('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJson([
            'introspection_endpoint' => 'https://id.example.test/oauth/introspect',
            'introspection_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
            ],
        ]);
});

test('an active access token returns active and standard claims', function () {
    Date::setTestNow('2026-11-13 12:00:00');
    $tokens = issueTokens($this);

    introspectAs($this, $tokens['access_token'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'active' => true,
            'token_type' => 'Bearer',
            'client_id' => $this->client->getKey(),
            'username' => 'ada@example.test',
            'sub' => $this->user->subject,
            'iss' => 'https://id.example.test',
            'aud' => $this->client->getKey(),
            'scope' => 'openid profile email',
        ])
        ->assertJsonPath('exp', fn (mixed $expiry): bool => is_int($expiry) && $expiry > time())
        ->assertJsonPath('iat', Date::parse('2026-11-13 12:00:00')->getTimestamp());
});

test('client_secret_basic authenticates the introspection caller', function () {
    $tokens = issueTokens($this);

    $this->withBasicAuth($this->client->getKey(), (string) $this->client->plainSecret)
        ->post('/oauth/introspect', ['token' => $tokens['access_token']])
        ->assertOk()
        ->assertJson(['active' => true]);
});

test('an unknown token is inactive', function () {
    issueTokens($this);

    introspectAs($this, 'not-a-real-token')
        ->assertOk()
        ->assertExactJson(['active' => false]);
});

test('a revoked access token is inactive', function () {
    $tokens = issueTokens($this);

    $accessTokenId = json_decode(
        base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/'), true),
        true,
    )['jti'];

    Passport::token()->newQuery()->whereKey($accessTokenId)->update(['revoked' => true]);

    introspectAs($this, $tokens['access_token'])
        ->assertOk()
        ->assertExactJson(['active' => false]);
});

test('an expired access token is inactive', function () {
    Date::setTestNow('2026-11-13 12:00:00');
    $tokens = issueTokens($this);

    $accessTokenId = json_decode(
        base64_decode(strtr(explode('.', $tokens['access_token'])[1], '-_', '+/'), true),
        true,
    )['jti'];

    Passport::token()->newQuery()->whereKey($accessTokenId)->update([
        'expires_at' => Date::parse('2026-11-13 11:00:00'),
    ]);

    introspectAs($this, $tokens['access_token'])
        ->assertOk()
        ->assertExactJson(['active' => false]);
});

test('an active refresh token returns active and standard claims', function () {
    $tokens = issueTokens($this);

    introspectAs($this, $tokens['refresh_token'], ['token_type_hint' => 'refresh_token'])
        ->assertOk()
        ->assertJson([
            'active' => true,
            'token_type' => 'refresh_token',
            'client_id' => $this->client->getKey(),
            'sub' => $this->user->subject,
            'iss' => 'https://id.example.test',
            'scope' => 'openid profile email',
        ]);
});

test('a revoked refresh token is inactive', function () {
    $tokens = issueTokens($this);

    Passport::refreshToken()->newQuery()->update(['revoked' => true]);

    introspectAs($this, $tokens['refresh_token'], ['token_type_hint' => 'refresh_token'])
        ->assertOk()
        ->assertExactJson(['active' => false]);
});

test('missing client authentication is refused', function () {
    $tokens = issueTokens($this);

    $this->post('/oauth/introspect', ['token' => $tokens['access_token']])
        ->assertUnauthorized()
        ->assertJson(['error' => 'invalid_client']);
});

test('a wrong client secret is refused', function () {
    $tokens = issueTokens($this);

    $this->post('/oauth/introspect', [
        'token' => $tokens['access_token'],
        'client_id' => $this->client->getKey(),
        'client_secret' => 'wrong-secret',
    ])
        ->assertUnauthorized()
        ->assertJson(['error' => 'invalid_client']);
});

test('a public client cannot call introspection', function () {
    $publicClient = createOidcClient(isConfidential: false);
    $tokens = issueTokens($this);

    $this->post('/oauth/introspect', [
        'token' => $tokens['access_token'],
        'client_id' => $publicClient->getKey(),
    ])
        ->assertUnauthorized()
        ->assertJson(['error' => 'invalid_client']);
});

test('a client cannot introspect another client\'s token', function () {
    $tokens = issueTokens($this);
    $otherClient = createOidcClient();

    $this->post('/oauth/introspect', [
        'token' => $tokens['access_token'],
        'client_id' => $otherClient->getKey(),
        'client_secret' => $otherClient->plainSecret,
    ])
        ->assertOk()
        ->assertExactJson(['active' => false]);
});

test('a missing token parameter is invalid_request', function () {
    $this->post('/oauth/introspect', [
        'client_id' => $this->client->getKey(),
        'client_secret' => $this->client->plainSecret,
    ])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_request']);
});
