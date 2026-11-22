<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * Clear an account's two-factor secret and recovery codes so the person can
 * enrol again. Uses Fortify's disable action to keep the same cleared columns.
 */
final class ResetUserTwoFactorAction
{
    public function __construct(
        private readonly DisableTwoFactorAuthentication $disableTwoFactorAuthentication,
    ) {}

    public function execute(User $user): void
    {
        ($this->disableTwoFactorAuthentication)($user);
    }
}
