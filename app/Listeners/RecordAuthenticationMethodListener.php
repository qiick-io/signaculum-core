<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuthenticationMethodEnum;
use App\Services\AuthenticationContextService;
use Illuminate\Auth\Events\Login;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * Record RFC 8176 `amr` values for the current browser session at each auth step.
 *
 * Password-only: Login → `pwd`. Password + TOTP: Challenged → `pwd`, then ValidTwoFactor
 * → `otp` + `mfa`. Passkey: PasskeyVerified → `swk` (Login then skips because methods exist).
 */
final class RecordAuthenticationMethodListener
{
    public function __construct(
        private readonly AuthenticationContextService $authenticationContextService,
    ) {}

    public function handleLogin(Login $login): void
    {
        if ($login->guard !== 'web') {
            return;
        }

        if ($this->authenticationContextService->current() !== []) {
            return;
        }

        $this->authenticationContextService->replace(AuthenticationMethodEnum::Password);
    }

    public function handleTwoFactorChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        $this->authenticationContextService->replace(AuthenticationMethodEnum::Password);
    }

    public function handleValidTwoFactor(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        $this->authenticationContextService->record(AuthenticationMethodEnum::OneTimePassword);
    }

    public function handlePasskeyVerified(PasskeyVerified $event): void
    {
        // ponytail: always swk; ceiling: cannot tell platform vs cross-platform yet;
        // upgrade: map authenticatorAttachment / transports to hwk when available.
        $this->authenticationContextService->replace(AuthenticationMethodEnum::SoftwareKey);
    }
}
