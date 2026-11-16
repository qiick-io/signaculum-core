<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuthenticationMethodEnum;
use App\Models\EmailLoginCode;
use App\Models\User;
use App\Services\AuthenticationContextService;
use App\Services\LoginLockoutService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Redeems a single-use email sign-in code and either starts a web session or stages
 * Fortify's two-factor challenge when the user has TOTP enabled.
 *
 * Side effects: marks the code consumed; records `otp`; Auth::login + session regenerate
 * when no second factor, otherwise `login.id` / `login.remember` for `two-factor.login`;
 * clears lockout counters on success.
 */
final class VerifyEmailLoginCodeAction
{
    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly LoginLockoutService $loginLockoutService,
        private readonly AuthenticationContextService $authenticationContextService,
    ) {}

    /**
     * @return bool True when the caller must redirect to the Fortify two-factor challenge.
     */
    public function execute(Request $request, string $email, string $code): bool
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
        $this->loginLockoutService->clear($user);

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            return true;
        }

        Auth::login($user, remember: false);
        $request->session()->regenerate();

        return false;
    }
}
