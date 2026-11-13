<?php

declare(strict_types=1);

use App\Jobs\SendBackChannelLogoutJob;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->client->forceFill([
        'backchannel_logout_uri' => 'https://client.example.test/auth/oidc/backchannel-logout',
        'is_backchannel_logout_session_required' => true,
    ])->save();
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

test('prompt=login re-authentication does not fan out back-channel logout', function () {
    Queue::fake();

    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $sid = session(OidcSessionService::SESSION_KEY);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();

    $freshPkce = pkcePair();
    $this->get(authorizeUrl($this->client, $freshPkce['challenge'], ['prompt' => 'login']))->assertRedirect('/login');

    Queue::assertNotPushed(SendBackChannelLogoutJob::class);

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    expect(session(OidcSessionService::SESSION_KEY))->toBe($sid);
    Queue::assertNotPushed(SendBackChannelLogoutJob::class);
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

test('max_age re-authentication does not fan out back-channel logout', function () {
    Queue::fake();

    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $sid = session(OidcSessionService::SESSION_KEY);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();

    Date::setTestNow('2026-11-05 10:10:00');
    $freshPkce = pkcePair();
    $this->get(authorizeUrl($this->client, $freshPkce['challenge'], ['max_age' => '60']))->assertRedirect('/login');

    Queue::assertNotPushed(SendBackChannelLogoutJob::class);

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    expect(session(OidcSessionService::SESSION_KEY))->toBe($sid);
    Queue::assertNotPushed(SendBackChannelLogoutJob::class);
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
        ->and($query['state'])->toBe('state-123')
        ->and($query['iss'])->toBe('https://id.example.test');
});

test('login_required preserves an existing query string on the redirect URI', function () {
    $redirectUriWithQuery = 'https://client.example.test/auth/oidc/callback?tenant=acme';
    $this->client->forceFill(['redirect_uris' => [$redirectUriWithQuery]])->save();

    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    Date::setTestNow('2026-11-05 10:10:00');

    $response = $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'redirect_uri' => $redirectUriWithQuery,
        'max_age' => '60',
        'prompt' => 'none',
    ]));

    $location = $response->headers->get('Location');
    $query = redirectQuery($location);

    expect($location)->toStartWith($redirectUriWithQuery.'&')
        ->and($query['tenant'])->toBe('acme')
        ->and($query['error'])->toBe('login_required')
        ->and($query['state'])->toBe('state-123')
        ->and($query['iss'])->toBe('https://id.example.test');
});

test('max_age with prompt=none keeps the Signaculum session and does not fan out logout', function () {
    Queue::fake();

    Date::setTestNow('2026-11-05 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $sid = session(OidcSessionService::SESSION_KEY);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();

    Date::setTestNow('2026-11-05 10:10:00');
    $freshPkce = pkcePair();
    $response = $this->get(authorizeUrl($this->client, $freshPkce['challenge'], [
        'max_age' => '60',
        'prompt' => 'none',
    ]));

    $query = redirectQuery($response->headers->get('Location'));

    expect($query['error'])->toBe('login_required')
        ->and(auth()->check())->toBeTrue()
        ->and(auth()->id())->toBe($this->user->id)
        ->and(session(OidcSessionService::SESSION_KEY))->toBe($sid);

    Queue::assertNotPushed(SendBackChannelLogoutJob::class);
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
