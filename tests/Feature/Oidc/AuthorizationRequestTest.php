<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function () {
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('a guest is sent to the login page first', function () {
    $this->get(authorizeUrl($this->client, $this->pkce['challenge']))
        ->assertRedirect('/login');
});

test('a signed-in user sees the consent screen', function () {
    $this->actingAs(User::factory()->create());

    $this->get(authorizeUrl($this->client, $this->pkce['challenge']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oauth/authorize')
            ->where('clientName', 'Example app')
            ->has('scopes', 3)
            ->has('authToken'));
});

test('approving redirects back with a code and the original state', function () {
    $this->actingAs(User::factory()->create());

    $query = approveAuthorization($this->client, $this->pkce['challenge']);

    expect($query)->toHaveKey('code')
        ->and($query['state'])->toBe('state-123');
});

test('denying redirects back with access_denied and the state', function () {
    $this->actingAs(User::factory()->create());

    $this->get(authorizeUrl($this->client, $this->pkce['challenge']))->assertOk();

    $query = redirectQuery($this->delete('/oauth/authorize', ['auth_token' => session('authToken')])->headers->get('Location'));

    expect($query['error'])->toBe('access_denied')
        ->and($query['state'])->toBe('state-123');
});

test('PKCE is required, also for confidential clients', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'code_challenge' => null,
        'code_challenge_method' => null,
    ]));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('invalid_request')
        ->and($query['state'])->toBe('state-123')
        ->and($query)->not->toHaveKey('code');
});

test('the plain code challenge method is refused', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['verifier'], ['code_challenge_method' => 'plain']));

    expect(redirectQuery($response->headers->get('Location'))['error'])->toBe('invalid_request');
});

test('a nonce is required for openid requests', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['nonce' => null]));

    expect(redirectQuery($response->headers->get('Location'))['error'])->toBe('invalid_request');
});

test('the nonce and PKCE rules can be relaxed for the conformance suite', function () {
    config(['oidc.require_pkce' => false, 'oidc.require_nonce' => false]);
    $this->actingAs(User::factory()->create());

    $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'nonce' => null,
        'code_challenge' => null,
        'code_challenge_method' => null,
    ]))->assertOk();
});

test('an unregistered redirect uri is never redirected to', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'redirect_uri' => 'https://attacker.example.test/callback',
    ]));

    expect($response->getStatusCode())->toBe(401)
        ->and((string) $response->headers->get('Location'))->not->toContain('attacker');
});

test('a redirect uri that only shares a prefix with a registered one is refused', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'redirect_uri' => TEST_REDIRECT_URI.'/extra',
    ]));

    expect($response->getStatusCode())->toBe(401);
});

test('an unknown client is refused', function () {
    $this->actingAs(User::factory()->create());

    $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['client_id' => '00000000-0000-0000-0000-000000000000']))
        ->assertStatus(401);
});

test('prompt=none without a session returns login_required to the client', function () {
    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['prompt' => 'none']));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('login_required')
        ->and($query['state'])->toBe('state-123');
});

test('response types other than code are refused', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['response_type' => 'token']));

    expect($response->getStatusCode())->toBeGreaterThanOrEqual(300)
        ->and((string) $response->headers->get('Location'))->not->toContain('access_token');
});
