<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Principal;
use App\Models\User;

/**
 * Reactivate a previously deactivated IdP account so login is allowed again.
 * Clears the matching principal's deactivated_at. Tokens and sessions stay revoked;
 * the user must sign in fresh.
 */
final class ReactivateUserAction
{
    public function execute(User $user): void
    {
        $user->forceFill(['is_active' => true])->save();

        Principal::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('deactivated_at')
            ->update(['deactivated_at' => null]);
    }
}
