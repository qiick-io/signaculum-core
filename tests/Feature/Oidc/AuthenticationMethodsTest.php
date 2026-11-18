<?php

declare(strict_types=1);

use App\Enums\AuthenticationMethodEnum;
use App\Models\User;
use App\Services\AuthenticationContextService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('password login records pwd and the id_token always carries amr', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    expect(app(AuthenticationContextService::class)->methods())
        ->toBe([AuthenticationMethodEnum::Password->value]);

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('amr'))->toBe([AuthenticationMethodEnum::Password->value]);
});

test('authentication methods are stored on the auth code', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    approveAuthorization($this->client, $this->pkce['challenge']);

    $storedMethods = DB::table('oauth_auth_codes')
        ->where('user_id', $this->user->getAuthIdentifier())
        ->where('revoked', false)
        ->value('authentication_methods');

    expect($storedMethods)->not->toBeNull();

    $decodedMethods = is_string($storedMethods) ? json_decode($storedMethods, true) : $storedMethods;

    expect($decodedMethods)->toBe([AuthenticationMethodEnum::Password->value]);
});

test('totp challenge records pwd otp and mfa in the id_token', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'mfa@example.test',
        'two_factor_secret' => encrypt($secret),
    ]);

    $this->post('/login', ['email' => 'mfa@example.test', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->post('/two-factor-challenge', [
        'code' => $google2fa->getCurrentOtp($secret),
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);

    expect(app(AuthenticationContextService::class)->methods())
        ->toBe([
            AuthenticationMethodEnum::Password->value,
            AuthenticationMethodEnum::OneTimePassword->value,
            AuthenticationMethodEnum::MultipleFactors->value,
        ]);

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('amr'))->toBe([
        AuthenticationMethodEnum::Password->value,
        AuthenticationMethodEnum::OneTimePassword->value,
        AuthenticationMethodEnum::MultipleFactors->value,
    ]);
});

test('a hardware passkey records hwk without pwd', function () {
    $this->get('/login')->assertOk();

    $passkey = new Passkey([
        'name' => 'YubiKey',
        'credential_id' => 'hardware-key',
        'credential' => ['transports' => ['usb'], 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $this->user->id;

    event(new PasskeyVerified($this->user, $passkey));
    event(new Login('web', $this->user, false));

    expect(app(AuthenticationContextService::class)->methods())
        ->toBe([AuthenticationMethodEnum::HardwareKey->value]);

    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('amr'))->toBe([AuthenticationMethodEnum::HardwareKey->value]);
});

test('methods proven for another account are not reused by a later login', function () {
    $this->get('/login')->assertOk();

    $otherUser = User::factory()->create(['is_active' => false]);
    $passkey = new Passkey([
        'name' => 'Other key',
        'credential_id' => 'other-account-key',
        'credential' => ['transports' => ['internal'], 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $otherUser->id;

    // Passkey login verifies the assertion before refusing an inactive account.
    event(new PasskeyVerified($otherUser, $passkey));
    event(new Login('web', $this->user, false));

    expect(app(AuthenticationContextService::class)->methods())
        ->toBe([AuthenticationMethodEnum::Password->value]);
});

test('a platform passkey records swk', function () {
    $this->get('/login')->assertOk();

    $passkey = new Passkey([
        'name' => 'Touch ID',
        'credential_id' => 'platform-key',
        'credential' => ['transports' => ['internal'], 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $this->user->id;

    event(new PasskeyVerified($this->user, $passkey));

    expect(app(AuthenticationContextService::class)->methods())
        ->toBe([AuthenticationMethodEnum::SoftwareKey->value]);
});

test('acting as a user without a recorded method still emits amr with pwd', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->has('amr'))->toBeTrue()
        ->and($claims->get('amr'))->toBe([AuthenticationMethodEnum::Password->value]);
});

test('discovery lists amr among claims_supported', function () {
    expect($this->get('/.well-known/openid-configuration')->json('claims_supported'))
        ->toContain('amr');
});

test('valid two-factor event alone records pwd otp and mfa', function () {
    $this->get('/login')->assertOk();

    event(new ValidTwoFactorAuthenticationCodeProvided($this->user));

    expect(app(AuthenticationContextService::class)->methods())
        ->toBe([
            AuthenticationMethodEnum::Password->value,
            AuthenticationMethodEnum::OneTimePassword->value,
            AuthenticationMethodEnum::MultipleFactors->value,
        ]);
});

test('logout clears recorded authentication methods', function () {
    Date::setTestNow('2026-11-13 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();
    expect(app(AuthenticationContextService::class)->methods())->not->toBeEmpty();

    $this->post('/logout')->assertRedirect();

    expect(app(AuthenticationContextService::class)->methods())->toBe([]);
});

test('interactive password login with remember still records pwd', function () {
    $this->post('/login', [
        'email' => 'ada@example.test',
        'password' => 'password',
        'remember' => true,
    ])->assertRedirect();

    expect(Auth::guard('web')->viaRemember())->toBeFalse()
        ->and(app(AuthenticationContextService::class)->methods())
        ->toBe([AuthenticationMethodEnum::Password->value]);
});

test('remember-me recaller login does not record pwd', function () {
    $rememberToken = 'recaller-amr-token';
    $this->user->forceFill(['remember_token' => $rememberToken])->save();

    $guard = Auth::guard('web');
    $recallerValue = implode('|', [
        (string) $this->user->getAuthIdentifier(),
        $rememberToken,
        $guard->hashPasswordForCookie($this->user->getAuthPassword()),
    ]);

    $this->withCookie($guard->getRecallerName(), $recallerValue)
        ->get(route('dashboard'))
        ->assertOk();

    $this->assertAuthenticatedAs($this->user);

    expect(Auth::guard('web')->viaRemember())->toBeTrue()
        ->and(app(AuthenticationContextService::class)->methods())->toBe([]);
});
