<?php

declare(strict_types=1);

use App\Models\Client;
use Laravel\Passport\ClientRepository;

/**
 * OAuth 2.0 client_credentials for machine clients (#51).
 *
 * Confidential applications must opt in via grant_types. The token response is
 * an access token only — no id_token (no end-user) and no refresh_token.
 */
beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);
});

/**
 * Confidential client with client_credentials opted in (and auth code kept).
 */
function createMachineCapableClient(): Client
{
    /** @var Client $client */
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Machine job',
        [TEST_REDIRECT_URI],
        true,
    );

    $plainSecret = $client->plainSecret;

    $client->forceFill([
        'grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
    ])->save();

    $client->plainSecret = $plainSecret;

    return $client;
}

test('discovery advertises client_credentials among grant_types_supported', function () {
    $this->get('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJsonFragment([
            'grant_types_supported' => [
                'authorization_code',
                'refresh_token',
                'client_credentials',
            ],
        ]);
});

test('an opted-in confidential client receives an access token without id_token', function () {
    $client = createMachineCapableClient();

    $response = $this->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'scope' => '',
    ])
        ->assertOk()
        ->assertJsonStructure(['token_type', 'expires_in', 'access_token']);

    expect($response->json())->not->toHaveKey('id_token')
        ->and($response->json())->not->toHaveKey('refresh_token')
        ->and($response->json('token_type'))->toBe('Bearer');
});

test('a confidential client without the grant is refused', function () {
    $client = createOidcClient(isConfidential: true);

    expect($client->hasGrantType('client_credentials'))->toBeFalse();

    $this->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
    ])
        ->assertStatus(400)
        ->assertJson(['error' => 'unauthorized_client']);
});

test('a public client cannot use client_credentials even if grant_types list it', function () {
    $client = createOidcClient(isConfidential: false);
    $client->forceFill([
        'grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
    ])->save();

    // No secret → invalid_client; the grant never issues a machine token for public apps.
    $this->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
    ])
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_client']);
});

test('a wrong client secret is refused as invalid_client', function () {
    $client = createMachineCapableClient();

    $this->post('/oauth/token', [
        'grant_type' => 'client_credentials',
        'client_id' => $client->getKey(),
        'client_secret' => 'wrong-secret',
    ])
        ->assertStatus(401)
        ->assertJson(['error' => 'invalid_client']);
});
