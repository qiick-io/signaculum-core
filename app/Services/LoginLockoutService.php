<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WebhookEventEnum;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Temporary account lock after too many failed password logins (instance settings).
 *
 * Side effects: updates `failed_login_attempts` / `locked_until`; writes an `auth`
 * activity entry when a lock is applied; queues `user.locked` webhooks.
 */
final class LoginLockoutService
{
    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly WebhookDispatcherService $webhookDispatcher,
    ) {}

    public function isLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    /**
     * Clear failure state after a successful password login (or admin unlock).
     */
    public function clear(User $user): void
    {
        if ($user->failed_login_attempts === 0 && $user->locked_until === null) {
            return;
        }

        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }

    /**
     * Record a failed password attempt. Locks the account when the threshold is reached.
     */
    public function recordFailure(User $user): void
    {
        if ($this->isLocked($user)) {
            return;
        }

        $attempts = $user->failed_login_attempts + 1;
        $threshold = $this->settings->loginLockoutThreshold();
        $attributes = ['failed_login_attempts' => $attempts];

        if ($attempts >= $threshold) {
            $attributes['locked_until'] = Carbon::now()->addMinutes($this->settings->loginLockoutMinutes());
            $attributes['failed_login_attempts'] = 0;
        }

        $user->forceFill($attributes)->save();

        if (isset($attributes['locked_until'])) {
            activity()
                ->performedOn($user)
                ->causedByAnonymous()
                ->useLog('auth')
                ->event('locked')
                ->withProperties([
                    'locked_until' => $user->locked_until?->toIso8601String(),
                    'threshold' => $threshold,
                ])
                ->log('Account locked after failed login attempts');

            $this->webhookDispatcher->dispatch(WebhookEventEnum::UserLocked, [
                'id' => $user->subject,
                'email' => $user->email,
                'locked_until' => $user->locked_until?->toIso8601String(),
            ]);
        }
    }
}
