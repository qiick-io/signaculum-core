<?php

declare(strict_types=1);

use App\Models\User;

/**
 * OIDC Core §3.1.2.1: the authorization endpoint MUST accept GET and POST.
 * POST parameters are form-serialized; behaviour matches GET (PKCE, nonce,
 * redirect URI, prompt / max_age, login / consent). Passport's consent
 * approve POST stays CSRF-protected (see VerifyCsrfTokenMiddleware unit test).
 */
beforeEach(function () {
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * Form body for an authorization request (OIDC Core §3.1.2.1 form serialization).
 *
 * @param  array<string, string|null>  $overrides
 * @return array<string, string>
 */
function authorizeFormParameters(string $clientId, string $codeChallenge, array $overrides = []): array
{
    return array_filter([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => TEST_REDIRECT_URI,
        'scope' => 'openid profile email',
        'state' => 'state-123',
        'nonce' => 'nonce-456',
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        ...$overrides,
    ], fn (?string $value): bool => $value !== null);
}

test('a form post authorization request shows the consent screen like get', function () {
    $this->actingAs(User::factory()->create());

    $this->post('/oauth/authorize', authorizeFormParameters(
        (string) $this->client->getKey(),
        $this->pkce['challenge'],
    ))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oauth/authorize')
            ->where('clientName', 'Example app')
            ->has('authToken'));
});

test('approving after a form post authorization request returns a code and state', function () {
    $this->actingAs(User::factory()->create());

    $authorization = $this->post('/oauth/authorize', authorizeFormParameters(
        (string) $this->client->getKey(),
        $this->pkce['challenge'],
    ));

    if ($authorization->isRedirect()) {
        $query = redirectQuery($authorization->headers->get('Location'));
    } else {
        $authorization->assertOk();
        $query = redirectQuery(
            $this->post('/oauth/authorize', ['auth_token' => session('authToken')])->headers->get('Location'),
        );
    }

    expect($query)->toHaveKey('code')
        ->and($query['state'])->toBe('state-123')
        ->and($query['iss'])->toBe((string) config('oidc.issuer'));
});

test('consent approval after get authorize still returns a code', function () {
    $this->actingAs(User::factory()->create());

    $query = approveAuthorization($this->client, $this->pkce['challenge']);

    expect($query)->toHaveKey('code')
        ->and($query['state'])->toBe('state-123');
});

test('a guest form post authorization request is sent to login', function () {
    $this->post('/oauth/authorize', authorizeFormParameters(
        (string) $this->client->getKey(),
        $this->pkce['challenge'],
    ))->assertRedirect('/login');
});

test('pkce is still required on a form post authorization request', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post('/oauth/authorize', authorizeFormParameters(
        (string) $this->client->getKey(),
        $this->pkce['challenge'],
        [
            'code_challenge' => null,
            'code_challenge_method' => null,
        ],
    ));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('invalid_request')
        ->and($query['state'])->toBe('state-123')
        ->and($query)->not->toHaveKey('code');
});

test('request on a form post authorization request is rejected as unsupported', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->post('/oauth/authorize', authorizeFormParameters(
        (string) $this->client->getKey(),
        $this->pkce['challenge'],
        ['request' => 'eyJhbGciOiJub25lIn0.e30.'],
    ));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('request_not_supported')
        ->and($query)->not->toHaveKey('code');
});
