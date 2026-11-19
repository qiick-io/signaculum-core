<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\EmailLoginCode;
use App\Models\User;
use App\Notifications\EmailLoginCodeNotification;
use App\Services\LoginLockoutService;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Issues a 6-digit email sign-in code when the instance allows it.
 *
 * Side effects: inserts a hashed `email_login_codes` row; queues EmailLoginCodeNotification
 * only when the email matches an active, unlocked, non-LDAP user (same response otherwise so
 * we do not reveal account existence). LDAP-sourced accounts are refused explicitly (#187):
 * the directory manages their sign-in.
 */
final class SendEmailLoginCodeAction
{
    public const int CODE_LENGTH = 6;

    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly LoginLockoutService $loginLockoutService,
    ) {}

    /**
     * @throws ValidationException when the address belongs to an LDAP-sourced user
     */
    public function execute(string $email): void
    {
        if (! $this->settings->isEmailCodeLoginEnabled()) {
            abort(404);
        }

        $normalizedEmail = strtolower(trim($email));
        $user = User::query()->where('email', $normalizedEmail)->first();

        // Same silent path for missing, inactive, and locked accounts so send never
        // reveals whether the address exists; verify still enforces lockout.
        if ($user === null || ! (bool) $user->is_active || $this->loginLockoutService->isLocked($user)) {
            return;
        }

        if ($user->isLdapSourced()) {
            throw ValidationException::withMessages([
                'email' => __('This account\'s sign-in is managed by the directory. Contact your administrator.'),
            ]);
        }

        $plainCode = str_pad((string) random_int(0, 999_999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
        $expireMinutes = (int) config('auth.email_login_code_expire_minutes', 10);

        EmailLoginCode::query()->create([
            'email' => $normalizedEmail,
            'code_hash' => Hash::make($plainCode),
            'expires_at' => Date::now()->addMinutes($expireMinutes),
        ]);

        $user->notify(new EmailLoginCodeNotification($plainCode, $expireMinutes));
    }
}
