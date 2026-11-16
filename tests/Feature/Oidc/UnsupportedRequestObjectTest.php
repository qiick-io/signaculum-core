<?php

declare(strict_types=1);

use App\Models\User;

/**
 * OIDC Core §6.1 / §3.1.2.6: when Request Objects are not supported, advertise
 * that in discovery and reject `request` / `request_uri` with the dedicated
 * error codes instead of issuing a broken success response.
 */
beforeEach(function () {
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('discovery advertises that request and request_uri are unsupported', function () {
    $document = $this->getJson('/.well-known/openid-configuration')->json();

    expect($document['request_parameter_supported'])->toBeFalse()
        ->and($document['request_uri_parameter_supported'])->toBeFalse();
});

test('an authorization request with request is rejected as request_not_supported', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'request' => 'eyJhbGciOiJub25lIn0.e30.',
    ]));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('request_not_supported')
        ->and($query['state'])->toBe('state-123')
        ->and($query['iss'])->toBe((string) config('oidc.issuer'))
        ->and($query)->not->toHaveKey('code');
});

test('an authorization request with request_uri is rejected as request_uri_not_supported', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'request_uri' => 'https://client.example.test/request.jwt',
    ]));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('request_uri_not_supported')
        ->and($query['state'])->toBe('state-123')
        ->and($query['iss'])->toBe((string) config('oidc.issuer'))
        ->and($query)->not->toHaveKey('code');
});

test('request takes precedence over request_uri when both are sent', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'request' => 'eyJhbGciOiJub25lIn0.e30.',
        'request_uri' => 'https://client.example.test/request.jwt',
    ]));

    expect(redirectQuery($response->headers->get('Location'))['error'])->toBe('request_not_supported');
});

test('request with an unregistered redirect_uri is never redirected to', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'request' => 'eyJhbGciOiJub25lIn0.e30.',
        'redirect_uri' => 'https://attacker.example.test/callback',
    ]));

    expect($response->getStatusCode())->toBe(401)
        ->and((string) $response->headers->get('Location'))->not->toContain('attacker');
});
