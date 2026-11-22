<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\LoginLockoutService;

/**
 * Admin unlock: clear failed-login counter and lock expiry so the user can sign in again.
 */
final class UnlockUserLoginAction
{
    public function __construct(private readonly LoginLockoutService $loginLockoutService) {}

    public function execute(User $user): void
    {
        $this->loginLockoutService->clear($user);
    }
}
