<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * @return array{access_token: string, refresh_token: string}
 */
function issueTokensFor(User $user, $client, array $pkce): array
{
    test()->actingAs($user);
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    $response = redeemCode($client, $code, $pkce['verifier'])->assertOk();

    return [
        'access_token' => (string) $response->json('access_token'),
        'refresh_token' => (string) $response->json('refresh_token'),
    ];
}

test('discovery advertises the revocation endpoint', function () {
    $this->get('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJsonPath('revocation_endpoint', 'https://id.example.test/oauth/revoke')
        ->assertJsonPath('revocation_endpoint_auth_methods_supported', [
            'client_secret_basic',
            'client_secret_post',
            'none',
        ]);
});

test('a confidential client can revoke its access token', function () {
    $tokens = issueTokensFor($this->user, $this->client, $this->pkce);

    $this->post('/oauth/revoke', [
        'token' => $tokens['access_token'],
        'token_type_hint' => 'access_token',
        'client_id' => $this->client->getKey(),
        'client_secret' => $this->client->plainSecret,
    ])->assertOk()->assertExactJson([]);

    expect(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0);

    $this->withToken($tokens['access_token'])
        ->get('/oauth/userinfo')
        ->assertUnauthorized();
});

test('a confidential client can revoke its refresh token and the related access token', function () {
    $tokens = issueTokensFor($this->user, $this->client, $this->pkce);

    $this->post('/oauth/revoke', [
        'token' => $tokens['refresh_token'],
        'token_type_hint' => 'refresh_token',
        'client_id' => $this->client->getKey(),
        'client_secret' => $this->client->plainSecret,
    ])->assertOk();

    expect(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(0)
        ->and(DB::table('oauth_refresh_tokens')->where('revoked', false)->count())->toBe(0);
});

test('revoking an unknown token still returns 200', function () {
    $this->post('/oauth/revoke', [
        'token' => 'not-a-real-token',
        'client_id' => $this->client->getKey(),
        'client_secret' => $this->client->plainSecret,
    ])->assertOk()->assertExactJson([]);
});

test('a confidential client cannot revoke another clients token', function () {
    $tokens = issueTokensFor($this->user, $this->client, $this->pkce);
    $foreignClient = createOidcClient();

    $this->post('/oauth/revoke', [
        'token' => $tokens['access_token'],
        'client_id' => $foreignClient->getKey(),
        'client_secret' => $foreignClient->plainSecret,
    ])->assertOk();

    expect(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(1);
});

test('a wrong client secret is refused', function () {
    $tokens = issueTokensFor($this->user, $this->client, $this->pkce);

    $this->post('/oauth/revoke', [
        'token' => $tokens['access_token'],
        'client_id' => $this->client->getKey(),
        'client_secret' => 'wrong-secret',
    ])
        ->assertUnauthorized()
        ->assertJsonPath('error', 'invalid_client');

    expect(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(1);
});

test('client_secret_basic authenticates the revocation request', function () {
    $tokens = issueTokensFor($this->user, $this->client, $this->pkce);
    $basic = base64_encode($this->client->getKey().':'.$this->client->plainSecret);

    $this->withHeader('Authorization', 'Basic '.$basic)
        ->post('/oauth/revoke', [
            'token' => $tokens['access_token'],
        ])
        ->assertOk();

    expect(DB::table('oauth_access_tokens')->where('revoked', false)->count())->toBe(0);
});

test('a public client can revoke its own access token without a secret', function () {
    $publicClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Public app',
        [TEST_REDIRECT_URI],
        false,
    );
    $pkce = pkcePair();
    $tokens = issueTokensFor($this->user, $publicClient, $pkce);

    $this->post('/oauth/revoke', [
        'token' => $tokens['access_token'],
        'client_id' => $publicClient->getKey(),
    ])->assertOk();

    expect(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(0);
});
