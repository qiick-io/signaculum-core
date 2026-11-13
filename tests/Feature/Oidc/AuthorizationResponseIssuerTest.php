<?php

declare(strict_types=1);

use App\Models\User;

/**
 * RFC 9207 `iss` on authorization responses (#44).
 */
beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('a successful authorization redirect carries iss', function () {
    $this->actingAs(User::factory()->create());

    $query = approveAuthorization($this->client, $this->pkce['challenge']);

    expect($query['iss'])->toBe('https://id.example.test')
        ->and($query)->toHaveKey('code');
});

test('an error authorization redirect carries iss', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'code_challenge' => null,
        'code_challenge_method' => null,
    ]));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('invalid_request')
        ->and($query['iss'])->toBe('https://id.example.test')
        ->and($query['state'])->toBe('state-123');
});

test('discovery advertises authorization_response_iss_parameter_supported', function () {
    $this->getJson('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJson([
            'authorization_response_iss_parameter_supported' => true,
        ]);
});
