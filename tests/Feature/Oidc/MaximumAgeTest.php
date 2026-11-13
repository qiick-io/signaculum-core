<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * OIDC Core §3.1.2.1 `max_age` and Passport's `prompt` behaviour that Signaculum relies on.
 * Passport already implements `prompt=none|login|consent`; this suite locks that in and
 * covers the `max_age` middleware Signaculum adds on top.
 */
test('prompt=login with a signed-in user forces a fresh login', function () {
    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['prompt' => 'login']));

    $response->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

test('prompt=consent shows the consent screen even when scopes were already granted', function () {
    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    approveAuthorization($this->client, $this->pkce['challenge']);

    $freshPkce = pkcePair();
    $this->get(authorizeUrl($this->client, $freshPkce['challenge'], ['prompt' => 'consent']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('oauth/authorize'));
});

test('max_age within the authentication age continues the authorization', function () {
    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    Date::setTestNow('2026-11-05 10:02:00');

    $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['max_age' => '300']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('oauth/authorize'));
});

test('max_age older than auth_time forces re-authentication', function () {
    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    Date::setTestNow('2026-11-05 10:10:00');

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['max_age' => '60']));

    $response->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});

test('max_age older than auth_time with prompt=none returns login_required', function () {
    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    Date::setTestNow('2026-11-05 10:10:00');

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'max_age' => '60',
        'prompt' => 'none',
    ]));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('login_required')
        ->and($query['state'])->toBe('state-123');
});

test('when max_age was sent the id_token always carries auth_time', function () {
    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    Date::setTestNow('2026-11-05 10:01:00');
    $code = approveAuthorization($this->client, $this->pkce['challenge'], ['max_age' => '600'])['code'];

    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('auth_time'))->toBe(Date::parse('2026-11-05 10:00:00')->getTimestamp());
});

test('a missing auth_time with max_age forces re-authentication', function () {
    $this->actingAs($this->user);

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], ['max_age' => '300']));

    $response->assertRedirect('/login');
    expect(auth()->check())->toBeFalse();
});
