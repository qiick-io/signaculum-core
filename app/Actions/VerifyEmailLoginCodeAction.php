<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuthenticationMethodEnum;
use App\Models\EmailLoginCode;
use App\Models\User;
use App\Services\AuthenticationContextService;
use App\Services\LoginLockoutService;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Redeems a single-use email sign-in code and starts a web session with `amr` = `otp` only.
 *
 * Side effects: marks the code consumed; Auth::login; records `otp` (never invents `mfa`);
 * clears lockout counters on success.
 */
final class VerifyEmailLoginCodeAction
{
    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly LoginLockoutService $loginLockoutService,
        private readonly AuthenticationContextService $authenticationContextService,
    ) {}

    public function execute(string $email, string $code): User
    {
        if (! $this->settings->isEmailCodeLoginEnabled()) {
            abort(404);
        }

        $normalizedEmail = strtolower(trim($email));
        $user = User::query()->where('email', $normalizedEmail)->first();

        if ($user === null || ! (bool) $user->is_active) {
            throw ValidationException::withMessages([
                'code' => __('The sign-in code is invalid or has expired.'),
            ]);
        }

        if ($this->loginLockoutService->isLocked($user)) {
            throw ValidationException::withMessages([
                'email' => __('This account is temporarily locked. Try again later.'),
            ]);
        }

        $emailLoginCode = EmailLoginCode::query()
            ->where('email', $normalizedEmail)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Date::now())
            ->latest('id')
            ->first();

        if ($emailLoginCode === null || ! Hash::check($code, $emailLoginCode->code_hash)) {
            $this->loginLockoutService->recordFailure($user);

            throw ValidationException::withMessages([
                'code' => __('The sign-in code is invalid or has expired.'),
            ]);
        }

        $emailLoginCode->forceFill(['consumed_at' => Date::now()])->save();

        $this->authenticationContextService->reset();
        $this->authenticationContextService->record(AuthenticationMethodEnum::OneTimePassword);

        Auth::login($user, remember: false);
        $this->loginLockoutService->clear($user);

        return $user;
    }
}
