<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Named OIDC / auth rate limiters return 429 once the per-minute budget is spent.
 */
beforeEach(function (): void {
    config([
        'oidc.rate_limits.token_per_minute' => 1,
        'oidc.rate_limits.authorize_per_minute' => 1,
        'oidc.rate_limits.end_session_per_minute' => 1,
        'oidc.rate_limits.password_reset_per_minute' => 1,
    ]);
});

test('the token endpoint is rate limited by oidc-token', function () {
    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => 'not-a-client',
        'code' => 'x',
        'redirect_uri' => TEST_REDIRECT_URI,
        'code_verifier' => 'verifier',
    ])->assertUnauthorized();

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => 'not-a-client',
        'code' => 'x',
        'redirect_uri' => TEST_REDIRECT_URI,
        'code_verifier' => 'verifier',
    ])->assertStatus(429);
});

test('the authorize endpoint is rate limited by oidc-authorize', function () {
    $client = createOidcClient();
    $codeChallenge = pkcePair()['challenge'];
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(authorizeUrl($client, $codeChallenge))
        ->assertOk();

    $this->actingAs($user)
        ->get(authorizeUrl($client, $codeChallenge))
        ->assertStatus(429);
});

test('end-session is rate limited by oidc-end-session', function () {
    $this->get('/oauth/end-session')->assertRedirect();

    $this->get('/oauth/end-session')->assertStatus(429);
});

test('password reset email is rate limited by password-reset', function () {
    $this->post('/forgot-password', [
        'email' => 'nobody@example.com',
    ])->assertRedirect();

    $this->post('/forgot-password', [
        'email' => 'nobody@example.com',
    ])->assertStatus(429);
});

test('the organization-invitations limiter is registered for future invite routes', function () {
    $limiter = RateLimiter::limiter('organization-invitations');

    expect($limiter)->toBeCallable();

    $limit = $limiter(Request::create('/'));

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(10);
});
