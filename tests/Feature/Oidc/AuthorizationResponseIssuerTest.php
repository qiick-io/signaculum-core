<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * RFC 9207: every authorization response to the client (success and error)
 * carries `iss`, identical to discovery's `issuer`.
 */
beforeEach(function (): void {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('a successful authorization response includes iss equal to the issuer', function (): void {
    $this->actingAs(User::factory()->create());

    $query = approveAuthorization($this->client, $this->pkce['challenge']);

    expect($query)->toHaveKey('code')
        ->and($query['iss'])->toBe('https://id.example.test')
        ->and($query['state'])->toBe('state-123');
});

test('an access_denied authorization response includes iss', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(authorizeUrl($this->client, $this->pkce['challenge']))->assertOk();

    $query = redirectQuery($this->delete('/oauth/authorize', ['auth_token' => session('authToken')])->headers->get('Location'));

    expect($query['error'])->toBe('access_denied')
        ->and($query['iss'])->toBe('https://id.example.test')
        ->and($query['state'])->toBe('state-123');
});

test('an invalid_request authorization error includes iss', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'code_challenge' => null,
        'code_challenge_method' => null,
    ]));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('invalid_request')
        ->and($query['iss'])->toBe('https://id.example.test')
        ->and($query['state'])->toBe('state-123')
        ->and($query)->not->toHaveKey('code');
});

test('prompt=none login_required includes iss', function (): void {
    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['prompt' => 'none']));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('login_required')
        ->and($query['iss'])->toBe('https://id.example.test')
        ->and($query['state'])->toBe('state-123');
});

// RFC 6749 §4.1.2.1: an error redirect carries only the defined parameters (plus RFC 9207 iss).
test('authorization error redirects carry no league hint parameter', function (): void {
    $loginRequired = redirectQuery($this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['prompt' => 'none']))
        ->headers->get('Location'));

    $this->actingAs(User::factory()->create());
    $invalidRequest = redirectQuery($this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'code_challenge' => null,
        'code_challenge_method' => null,
    ]))->headers->get('Location'));

    expect(array_keys($loginRequired))->toEqualCanonicalizing(['error', 'error_description', 'state', 'iss'])
        ->and(array_keys($invalidRequest))->toEqualCanonicalizing(['error', 'error_description', 'state', 'iss'])
        ->and($invalidRequest['error_description'])->toContain('PKCE is required');
});

test('a guest login redirect does not receive iss', function (): void {
    $location = $this->get(authorizeUrl($this->client, $this->pkce['challenge']))
        ->headers
        ->get('Location');

    expect($location)->toContain('/login')
        ->and((string) parse_url((string) $location, PHP_URL_QUERY))->not->toContain('iss=');
});

test('an unregistered redirect uri still returns 401 without an iss redirect', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'redirect_uri' => 'https://attacker.example.test/callback',
    ]));

    expect($response->getStatusCode())->toBe(401)
        ->and((string) $response->headers->get('Location'))->not->toContain('iss=')
        ->and((string) $response->headers->get('Location'))->not->toContain('attacker');
});

test('an unrelated web redirect with an error query is left without iss', function (): void {
    Route::middleware('web')->get('/__test/unrelated-error-redirect', function () {
        return redirect('https://other.example.test/callback?error=access_denied&state=keep-me');
    });

    $location = $this->get('/__test/unrelated-error-redirect')->headers->get('Location');

    expect($location)->toBe('https://other.example.test/callback?error=access_denied&state=keep-me')
        ->and((string) parse_url((string) $location, PHP_URL_QUERY))->not->toContain('iss=');
});
