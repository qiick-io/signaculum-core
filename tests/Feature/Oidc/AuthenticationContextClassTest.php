<?php

declare(strict_types=1);

use App\Enums\AuthenticationContextClassEnum;
use App\Enums\AuthenticationMethodEnum;
use App\Models\User;
use App\Services\AuthenticationContextService;
use Illuminate\Support\Facades\Date;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

/**
 * OIDC `acr` is emitted only when requested, as urn:qiick:loa:pwd or urn:qiick:loa:mfa.
 */
beforeEach(function (): void {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('without acr_values the id_token has no acr claim', function (): void {
    Date::setTestNow('2026-11-13 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('amr'))->toBe([AuthenticationMethodEnum::Password->value])
        ->and($claims->has('acr'))->toBeFalse();
});

test('acr_values with password-only login emits urn:qiick:loa:pwd', function (): void {
    Date::setTestNow('2026-11-13 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'acr_values' => AuthenticationContextClassEnum::MultipleFactors->value,
    ])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('acr'))->toBe(AuthenticationContextClassEnum::Password->value)
        ->and($claims->get('amr'))->toBe([AuthenticationMethodEnum::Password->value]);
});

test('claims parameter requesting acr emits urn:qiick:loa:pwd for password login', function (): void {
    Date::setTestNow('2026-11-13 10:00:00');
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'claims' => json_encode(['id_token' => ['acr' => null]], JSON_THROW_ON_ERROR),
    ])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('acr'))->toBe(AuthenticationContextClassEnum::Password->value);
});

test('acr_values with mfa login emits urn:qiick:loa:mfa', function (): void {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    User::factory()->withTwoFactor()->create([
        'email' => 'mfa@example.test',
        'two_factor_secret' => encrypt($secret),
    ]);

    $this->post('/login', ['email' => 'mfa@example.test', 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $this->post('/two-factor-challenge', [
        'code' => $google2fa->getCurrentOtp($secret),
    ])->assertRedirect();

    expect(app(AuthenticationContextService::class)->methods())
        ->toContain(AuthenticationMethodEnum::MultipleFactors->value);

    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'acr_values' => AuthenticationContextClassEnum::MultipleFactors->value,
    ])['code'];
    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('acr'))->toBe(AuthenticationContextClassEnum::MultipleFactors->value);
});

test('discovery advertises the two qiick acr values', function (): void {
    $document = $this->getJson('/.well-known/openid-configuration')->json();

    expect($document['acr_values_supported'])->toBe([
        AuthenticationContextClassEnum::Password->value,
        AuthenticationContextClassEnum::MultipleFactors->value,
    ])
        ->and($document['claims_supported'])->toContain('acr');
});
