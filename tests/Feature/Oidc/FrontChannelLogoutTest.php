<?php

declare(strict_types=1);

use App\Jobs\SendBackChannelLogoutJob;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

const FRONTCHANNEL_POST_LOGOUT_URI = 'https://client.example.test/logged-out';
const FRONTCHANNEL_LOGOUT_URI = 'https://client.example.test/frontchannel-logout';

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);
    Queue::fake();

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->client->forceFill([
        'post_logout_redirect_uris' => [FRONTCHANNEL_POST_LOGOUT_URI],
        'backchannel_logout_uri' => 'https://client.example.test/auth/oidc/backchannel-logout',
        'frontchannel_logout_uri' => FRONTCHANNEL_LOGOUT_URI,
        'is_frontchannel_logout_session_required' => false,
    ])->save();
    $this->pkce = pkcePair();
});

/**
 * @return array{id_token: string, sid: string}
 */
function issueFrontchannelIdToken($client, array $pkce): array
{
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    $idToken = redeemCode($client, $code, $pkce['verifier'])->json('id_token');

    return [
        'id_token' => $idToken,
        'sid' => verifiedIdToken($idToken)->claims()->get('sid'),
    ];
}

/**
 * Discovery advertises front-channel support (OIDC Front-Channel Logout 1.0 §4).
 */
test('discovery advertises front-channel logout support', function () {
    $this->get('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJson([
            'frontchannel_logout_supported' => true,
            'frontchannel_logout_session_supported' => true,
        ]);
});

/**
 * Without session required, the iframe uses the registered URI as-is (§2).
 */
test('end-session renders a front-channel iframe then continues', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $issued = issueFrontchannelIdToken($this->client, $this->pkce);

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $issued['id_token'],
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => FRONTCHANNEL_POST_LOGOUT_URI,
        'state' => 'fc-state',
    ]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/frontchannel-logout')
            ->where('iframeUrls', [FRONTCHANNEL_LOGOUT_URI])
            ->where('continueUrl', FRONTCHANNEL_POST_LOGOUT_URI.'?state=fc-state'));

    expect(Auth::guard('web')->check())->toBeFalse();
    Queue::assertPushed(SendBackChannelLogoutJob::class, 1);
});

/**
 * When session is required, iss and sid are both appended (§2, §3).
 */
test('front-channel iframe includes iss and sid when session is required', function () {
    $this->client->forceFill([
        'is_frontchannel_logout_session_required' => true,
        'frontchannel_logout_uri' => FRONTCHANNEL_LOGOUT_URI.'?existing=1',
    ])->save();

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $issued = issueFrontchannelIdToken($this->client, $this->pkce);

    $expectedIframeUrl = FRONTCHANNEL_LOGOUT_URI.'?existing=1&'.http_build_query([
        'iss' => 'https://id.example.test',
        'sid' => $issued['sid'],
    ]);

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $issued['id_token'],
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => FRONTCHANNEL_POST_LOGOUT_URI,
    ]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/frontchannel-logout')
            ->where('iframeUrls', [$expectedIframeUrl]));
});

/**
 * CSP allows only the RP frame origins on the interstitial, never globally.
 */
test('front-channel logout page CSP allows only the iframe origins', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $issued = issueFrontchannelIdToken($this->client, $this->pkce);

    $response = $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $issued['id_token'],
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => FRONTCHANNEL_POST_LOGOUT_URI,
    ]))->assertOk();

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain('frame-src')
        ->and($csp)->toContain('https://client.example.test')
        ->and($csp)->not->toContain('https://evil.example.test');

    $loginCsp = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    expect($loginCsp)->not->toContain('frame-src')
        ->and($loginCsp)->not->toContain('https://client.example.test');
});

/**
 * Confirm path also fans out front-channel iframes for the browser session.
 */
test('confirming logout renders front-channel iframes when registered', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    issueFrontchannelIdToken($this->client, $this->pkce);

    $this->post('/oauth/end-session/confirm', [
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => FRONTCHANNEL_POST_LOGOUT_URI,
        'state' => 'after-confirm',
    ])->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/frontchannel-logout')
            ->where('iframeUrls', [FRONTCHANNEL_LOGOUT_URI])
            ->where('continueUrl', FRONTCHANNEL_POST_LOGOUT_URI.'?state=after-confirm'));

    expect(Auth::guard('web')->check())->toBeFalse();
});

/**
 * Clients without a front-channel URI keep the direct post-logout redirect.
 */
test('end-session without a front-channel uri still redirects directly', function () {
    $this->client->forceFill(['frontchannel_logout_uri' => null])->save();

    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    $issued = issueFrontchannelIdToken($this->client, $this->pkce);

    $this->get('/oauth/end-session?'.http_build_query([
        'id_token_hint' => $issued['id_token'],
        'client_id' => $this->client->getKey(),
        'post_logout_redirect_uri' => FRONTCHANNEL_POST_LOGOUT_URI,
        'state' => 'direct',
    ]))->assertRedirect(FRONTCHANNEL_POST_LOGOUT_URI.'?state=direct');
});

/**
 * Light and dark appearance still render the front-channel interstitial.
 */
test('front-channel logout page renders in light and dark appearance', function () {
    foreach (['light', 'dark'] as $appearance) {
        $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
        $issued = issueFrontchannelIdToken($this->client, $this->pkce);

        $this->withUnencryptedCookie('appearance', $appearance)
            ->get('/oauth/end-session?'.http_build_query([
                'id_token_hint' => $issued['id_token'],
                'client_id' => $this->client->getKey(),
                'post_logout_redirect_uri' => FRONTCHANNEL_POST_LOGOUT_URI,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('oauth/frontchannel-logout'))
            ->assertSee("const appearance = '{$appearance}';", false);
    }
});

/**
 * Browser UI logout also renders front-channel iframes when registered.
 */
test('web logout renders front-channel iframes when registered', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    issueFrontchannelIdToken($this->client, $this->pkce);

    $this->post('/logout')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('oauth/frontchannel-logout')
            ->where('iframeUrls', [FRONTCHANNEL_LOGOUT_URI]));

    expect(Auth::guard('web')->check())->toBeFalse();
    Queue::assertPushed(SendBackChannelLogoutJob::class, 1);
});

test('an instance admin can persist front-channel logout fields on an application', function () {
    $admin = User::factory()->instanceAdmin()->create();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'Frontchannel App',
            'redirect_uris' => 'https://rp.example.test/callback',
            'is_confidential' => true,
            'is_first_party' => false,
            'is_client_credentials_enabled' => false,
            'is_device_authorization_enabled' => false,
            'is_pushed_authorization_required' => false,
            'frontchannel_logout_uri' => 'https://rp.example.test/frontchannel-logout',
            'is_frontchannel_logout_session_required' => true,
        ])
        ->assertRedirect(route('admin.applications.index'));

    $client = Client::query()->where('name', 'Frontchannel App')->sole();

    expect($client->frontchannel_logout_uri)->toBe('https://rp.example.test/frontchannel-logout')
        ->and($client->is_frontchannel_logout_session_required)->toBeTrue();
});

test('front-channel logout uri must be https without a fragment', function () {
    $admin = User::factory()->instanceAdmin()->create();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'Bad Frontchannel',
            'redirect_uris' => 'https://rp.example.test/callback',
            'is_confidential' => true,
            'is_first_party' => false,
            'is_client_credentials_enabled' => false,
            'is_device_authorization_enabled' => false,
            'is_pushed_authorization_required' => false,
            'frontchannel_logout_uri' => 'http://rp.example.test/frontchannel-logout#x',
            'is_frontchannel_logout_session_required' => false,
        ])
        ->assertSessionHasErrors('frontchannel_logout_uri');
});
