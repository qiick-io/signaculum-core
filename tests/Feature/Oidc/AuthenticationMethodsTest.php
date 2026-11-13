<?php

declare(strict_types=1);

use App\Enums\AuthenticationMethodEnum;
use App\Models\User;
use App\Services\AuthenticationContextService;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * RFC 8176 `amr` recording and emission in the id_token (S2 / #43).
 */
beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->user = User::factory()->create(['email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

test('a password login puts pwd in the id_token amr claim', function () {
    $this->post('/login', ['email' => 'ada@example.test', 'password' => 'password'])->assertRedirect();

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $idToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token');

    expect(verifiedIdToken($idToken)->claims()->get('amr'))->toBe([AuthenticationMethodEnum::Password->value]);
});

test('a password plus totp path puts pwd otp and mfa in amr', function () {
    $this->actingAs($this->user)->get(route('dashboard'))->assertOk();

    app(AuthenticationContextService::class)->replace(AuthenticationMethodEnum::Password);
    event(new ValidTwoFactorAuthenticationCodeProvided($this->user));

    expect(app(AuthenticationContextService::class)->current())->toBe([
        AuthenticationMethodEnum::Password->value,
        AuthenticationMethodEnum::OneTimePassword->value,
        AuthenticationMethodEnum::MultipleFactors->value,
    ]);

    $code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $idToken = redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token');

    expect(verifiedIdToken($idToken)->claims()->get('amr'))->toBe([
        AuthenticationMethodEnum::Password->value,
        AuthenticationMethodEnum::OneTimePassword->value,
        AuthenticationMethodEnum::MultipleFactors->value,
    ]);
});

test('two factor challenged replaces the session methods with pwd', function () {
    $this->actingAs($this->user)->get(route('dashboard'))->assertOk();

    app(AuthenticationContextService::class)->replace(AuthenticationMethodEnum::SoftwareKey);
    event(new TwoFactorAuthenticationChallenged($this->user));

    expect(app(AuthenticationContextService::class)->current())->toBe([
        AuthenticationMethodEnum::Password->value,
    ]);
});
