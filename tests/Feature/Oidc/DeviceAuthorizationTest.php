<?php

declare(strict_types=1);

use App\Enums\AuthenticationMethodEnum;
use App\Enums\OrganizationRoleEnum;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * RFC 8628 device authorization grant (#64).
 */
function deviceAuthorizationClient(): Client
{
    /** @var Client $client */
    $client = app(ClientRepository::class)->createDeviceAuthorizationGrantClient(
        'CLI device',
        confidential: false,
    );

    return $client;
}

/**
 * Issue a device code for the given client and scopes.
 *
 * @return array{device_code: string, user_code: string, interval: int}
 */
function issueDeviceCode(Client $client, string $scope = 'openid profile'): array
{
    $response = test()->postJson(route('passport.device.code'), [
        'client_id' => $client->getKey(),
        'scope' => $scope,
    ])->assertOk();

    return [
        'device_code' => (string) $response->json('device_code'),
        'user_code' => (string) $response->json('user_code'),
        'interval' => (int) $response->json('interval'),
    ];
}

/**
 * Open the device consent screen and approve (or deny) as the current user.
 */
function approveDeviceAuthorization(string $userCode, bool $isApproved = true): void
{
    test()->get(route('passport.device.authorizations.authorize', ['user_code' => $userCode]))
        ->assertOk();

    $authToken = session('authToken');

    expect($authToken)->toBeString()->not->toBeEmpty();

    if ($isApproved) {
        test()->post(route('passport.device.authorizations.approve'), [
            'auth_token' => $authToken,
        ])->assertRedirect();

        return;
    }

    test()->delete(route('passport.device.authorizations.deny'), [
        'auth_token' => $authToken,
    ])->assertRedirect();
}

/**
 * Poll the token endpoint with a device code (RFC 8628 §3.4).
 */
function redeemDeviceCode(Client $client, string $deviceCode)
{
    return test()->post('/oauth/token', [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        'client_id' => $client->getKey(),
        'device_code' => $deviceCode,
    ]);
}

test('discovery advertises the device authorization endpoint and grant', function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->get('/.well-known/openid-configuration')
        ->assertOk()
        ->assertJsonFragment([
            'device_authorization_endpoint' => 'https://id.example.test/oauth/device/code',
        ])
        ->assertJsonFragment([
            'grant_types_supported' => [
                'authorization_code',
                'refresh_token',
                'client_credentials',
                'urn:ietf:params:oauth:grant-type:device_code',
            ],
        ]);
});

test('passport device code grant is enabled', function () {
    expect(Passport::$deviceCodeGrantEnabled)->toBeTrue()
        ->and(route('passport.device'))->toEndWith('/oauth/device')
        ->and(route('passport.device.code'))->toEndWith('/oauth/device/code');
});

test('a device client receives a device code and verification uri', function () {
    $client = deviceAuthorizationClient();

    $response = $this->postJson(route('passport.device.code'), [
        'client_id' => $client->getKey(),
        'scope' => 'openid profile',
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'device_code',
            'user_code',
            'verification_uri',
            'verification_uri_complete',
            'expires_in',
            'interval',
        ]);

    expect($response->json('verification_uri'))->toContain('/oauth/device')
        ->and($response->json('user_code'))->not->toBeEmpty();
});

test('a client without the device grant is refused', function () {
    $client = createOidcClient();

    $this->postJson(route('passport.device.code'), [
        'client_id' => $client->getKey(),
        'scope' => 'openid',
    ])->assertStatus(400);
});

test('the device user-code page is public', function () {
    $this->get(route('passport.device'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('oauth/device/user-code'));
});

test('an authenticated user can open the device consent screen with a valid user code', function () {
    $client = deviceAuthorizationClient();
    $user = User::factory()->create();

    $device = issueDeviceCode($client);

    $this->actingAs($user)
        ->get(route('passport.device.authorizations.authorize', ['user_code' => $device['user_code']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oauth/device/authorize')
            ->where('clientName', 'CLI device')
            ->has('scopes')
            ->has('authToken'));
});

test('admin can opt an application into device authorization', function () {
    $admin = User::factory()->instanceAdmin()->create();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'TV app',
            'redirect_uris' => 'https://tv.example.test/callback',
            'is_confidential' => false,
            'is_first_party' => false,
            'is_client_credentials_enabled' => false,
            'is_device_authorization_enabled' => true,
        ])
        ->assertRedirect(route('admin.applications.index'));

    $application = Client::query()->where('name', 'TV app')->first();

    expect($application)->not->toBeNull()
        ->and($application->hasGrantType('urn:ietf:params:oauth:grant-type:device_code'))->toBeTrue();
});

test('approved device grant with openid returns an id_token from the approving session', function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $client = deviceAuthorizationClient();
    $user = User::factory()->create(['email' => 'device@example.test']);

    Date::setTestNow('2026-11-17 10:00:00');
    $this->post('/login', ['email' => 'device@example.test', 'password' => 'password'])->assertRedirect();

    $expectedSid = session(OidcSessionService::SESSION_KEY);
    $expectedAuthTime = Date::parse('2026-11-17 10:00:00')->getTimestamp();

    expect($expectedSid)->toBeString()->not->toBeEmpty();

    $device = issueDeviceCode($client, 'openid profile');

    Date::setTestNow('2026-11-17 10:05:00');
    approveDeviceAuthorization($device['user_code']);

    $response = redeemDeviceCode($client, $device['device_code'])
        ->assertOk()
        ->assertJsonStructure(['token_type', 'expires_in', 'access_token', 'refresh_token', 'id_token']);

    $claims = verifiedIdToken($response->json('id_token'))->claims();
    $rawPayload = json_decode(base64_decode(strtr(explode('.', $response->json('id_token'))[1], '-_', '+/')), true);

    expect($claims->get('sub'))->toBe($user->subject)
        ->and($claims->get('auth_time'))->toBe($expectedAuthTime)
        ->and($claims->get('amr'))->toBe([AuthenticationMethodEnum::Password->value])
        ->and($claims->get('sid'))->toBe($expectedSid)
        ->and(array_key_exists('nonce', $rawPayload))->toBeFalse()
        ->and(array_key_exists('acr', $rawPayload))->toBeFalse();
});

test('token poll before approval returns authorization_pending', function () {
    $client = deviceAuthorizationClient();
    $device = issueDeviceCode($client);

    redeemDeviceCode($client, $device['device_code'])
        ->assertStatus(400)
        ->assertJson(['error' => 'authorization_pending']);
});

test('token poll faster than interval returns slow_down', function () {
    $client = deviceAuthorizationClient();
    $device = issueDeviceCode($client);

    redeemDeviceCode($client, $device['device_code'])
        ->assertStatus(400)
        ->assertJson(['error' => 'authorization_pending']);

    redeemDeviceCode($client, $device['device_code'])
        ->assertStatus(400)
        ->assertJson(['error' => 'slow_down']);
});

test('token poll after expiry returns expired_token', function () {
    $client = deviceAuthorizationClient();
    $device = issueDeviceCode($client);

    DB::table('oauth_device_codes')
        ->where('id', $device['device_code'])
        ->update(['expires_at' => Date::now()->subMinute()]);

    redeemDeviceCode($client, $device['device_code'])
        ->assertStatus(400)
        ->assertJson(['error' => 'expired_token']);
});

test('token poll after user denial returns access_denied', function () {
    $client = deviceAuthorizationClient();
    $user = User::factory()->create();
    $device = issueDeviceCode($client);

    $this->actingAs($user);
    approveDeviceAuthorization($device['user_code'], isApproved: false);

    redeemDeviceCode($client, $device['device_code'])
        ->assertStatus(401)
        ->assertJson(['error' => 'access_denied']);
});

test('device user-code and device-code endpoints are rate limited by oidc-device', function () {
    config(['oidc.rate_limits.device_per_minute' => 1]);

    $client = deviceAuthorizationClient();

    $this->postJson(route('passport.device.code'), [
        'client_id' => $client->getKey(),
        'scope' => 'openid',
    ])->assertOk();

    $this->postJson(route('passport.device.code'), [
        'client_id' => $client->getKey(),
        'scope' => 'openid',
    ])->assertStatus(429);

    // User-code entry shares the same named limiter (RFC 8628 §5.1).
    $this->get(route('passport.device'))->assertStatus(429);
});

test('a member without 2FA is sent to security before device authorization when the org requires MFA', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'device-mfa-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $client = deviceAuthorizationClient();
    $device = issueDeviceCode($client);

    $this->actingAs($member)
        ->get(route('passport.device.authorizations.authorize', ['user_code' => $device['user_code']]))
        ->assertRedirect(route('security.edit'));

    expect(session('url.intended'))->toStartWith(url('/oauth/device/authorize'));
});
