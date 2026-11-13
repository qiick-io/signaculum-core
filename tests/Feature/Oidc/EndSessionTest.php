<?php

declare(strict_types=1);

use App\Models\ClientSession;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;

const TEST_POST_LOGOUT_URI = 'https://client.example.test/logged-out';

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);
    Queue::fake();

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->client->forceFill([
        'post_logout_redirect_uris' => [TEST_POST_LOGOUT_URI],
        'backchannel_logout_uri' => 'https://client.example.test/auth/oidc/backchannel-logout',
    ])->save();
    $this->pkce = pkcePair();
});

/**
 * @return array{id_token: string, sid: string}
 */
function issueIdTokenForCurrentUser($client, array $pkce): array
{
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    $idToken = redeemCode($client, $code, $pkce['verifier'])->json('id_token');

    return [
        'id_token' => $idToken,
        'sid' => verifiedIdToken($idToken)->claims()->get('sid'),
    ];
}

test('end-session with a valid id_token_hint ends the session and redirects', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $issued = issueIdTokenForCurrentUser($this->client, $this->pkce);

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $issued['id_token'],
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => TEST_POST_LOGOUT_URI,
        'state' => 'logout-state',
    ]))->assertRedirect(TEST_POST_LOGOUT_URI.'?state=logout-state');

    expect(Auth::guard('web')->check())->toBeFalse();
});

test('an unregistered post_logout_redirect_uri is refused', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $issued = issueIdTokenForCurrentUser($this->client, $this->pkce);

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $issued['id_token'],
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => 'https://evil.example.test/logged-out',
    ]))->assertStatus(400);
});

test('a forged id_token_hint shows the confirmation page for a signed-in user', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => 'not.a.jwt',
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => TEST_POST_LOGOUT_URI,
    ]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('oauth/logout'));
});

test('confirming logout ends the local session', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    expect(session(OidcSessionService::SESSION_KEY))->toBeString();

    $this->post('/oauth/end-session/confirm', [
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => TEST_POST_LOGOUT_URI,
        'state' => 'after-confirm',
    ])->assertRedirect(TEST_POST_LOGOUT_URI.'?state=after-confirm');

    expect(Auth::guard('web')->check())->toBeFalse();
});

test('an expired id_token_hint is still accepted when the signature is valid', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    config(['oidc.id_token_ttl' => 1]);
    $issued = issueIdTokenForCurrentUser($this->client, $this->pkce);

    $this->travel(10)->seconds();

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $issued['id_token'],
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => TEST_POST_LOGOUT_URI,
    ]))->assertRedirect(TEST_POST_LOGOUT_URI);
});

test('a guest with a valid id_token_hint does not end sessions or fan out', function () {
    $oidcSessionId = 'sid-guest-'.bin2hex(random_bytes(8));

    ClientSession::query()->create([
        'user_id' => $this->user->getKey(),
        'client_id' => $this->client->getKey(),
        'oidc_session_id' => $oidcSessionId,
    ]);

    $hint = mintSignedHint($this->user, $this->client->getKey(), ['sid' => $oidcSessionId]);

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $hint,
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => TEST_POST_LOGOUT_URI,
        'state' => 'guest-hint',
    ]))->assertRedirect(TEST_POST_LOGOUT_URI.'?state=guest-hint');

    Queue::assertNothingPushed();
    expect(ClientSession::query()->where('oidc_session_id', $oidcSessionId)->whereNull('ended_at')->exists())
        ->toBeTrue();
});

test('a signed-in user with another users hint and confirmed=1 only shows confirmation', function () {
    $otherUser = User::factory()->create(['email' => 'bob@example.test']);
    $otherOidcSessionId = 'sid-bob-'.bin2hex(random_bytes(8));

    ClientSession::query()->create([
        'user_id' => $otherUser->getKey(),
        'client_id' => $this->client->getKey(),
        'oidc_session_id' => $otherOidcSessionId,
    ]);

    $otherHint = mintSignedHint($otherUser, $this->client->getKey(), ['sid' => $otherOidcSessionId]);

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $otherHint,
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => TEST_POST_LOGOUT_URI,
        'confirmed' => '1',
    ]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('oauth/logout'));

    expect(Auth::guard('web')->check())->toBeTrue()
        ->and(Auth::guard('web')->id())->toBe($this->user->getKey());
    Queue::assertNothingPushed();
    expect(ClientSession::query()->where('oidc_session_id', $otherOidcSessionId)->whereNull('ended_at')->exists())
        ->toBeTrue();
});

test('confirmed=1 on the main end-session endpoint never skips confirmation', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => 'not.a.jwt',
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => TEST_POST_LOGOUT_URI,
        'confirmed' => '1',
    ]))->assertOk()
        ->assertInertia(fn ($page) => $page->component('oauth/logout'));

    expect(Auth::guard('web')->check())->toBeTrue();
    Queue::assertNothingPushed();
});
