<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuthenticationMethodEnum;
use App\Services\AuthenticationContextService;
use Illuminate\Auth\Events\Login;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * Record RFC 8176 authentication methods as the user proves each factor.
 *
 * - Password login → `pwd` (only when nothing was recorded yet, so passkey logins
 *   that fire `Login` afterwards do not pick up a false `pwd`).
 * - Fortify TOTP / recovery → `pwd` + `otp` (+ `mfa`), because the password was
 *   already verified to reach the challenge.
 * - Passkey verification → `hwk` or `swk` from the credential transports.
 */
final class RecordAuthenticationMethodListener
{
    public function __construct(
        private readonly AuthenticationContextService $authenticationContextService,
    ) {}

    public function handle(Login|ValidTwoFactorAuthenticationCodeProvided|PasskeyVerified $event): void
    {
        if ($event instanceof PasskeyVerified) {
            $this->authenticationContextService->recordPasskey($event->passkey);

            return;
        }

        if ($event instanceof ValidTwoFactorAuthenticationCodeProvided) {
            $this->authenticationContextService->record(AuthenticationMethodEnum::Password);
            $this->authenticationContextService->record(AuthenticationMethodEnum::OneTimePassword);

            return;
        }

        if ($event->guard !== 'web') {
            return;
        }

        if ($this->authenticationContextService->methods() !== []) {
            return;
        }

        $this->authenticationContextService->record(AuthenticationMethodEnum::Password);
    }
}
