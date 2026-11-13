<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Reactivate a previously deactivated IdP account so login is allowed again.
 * Tokens and sessions stay revoked; the user must sign in fresh.
 */
final class ReactivateUserAction
{
    public function execute(User $user): void
    {
        $user->forceFill(['is_active' => true])->save();
    }
}
