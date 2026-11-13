<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * The activity log is read by instance admins only (same rule as {@see SettingPolicy}).
 */
final class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_instance_admin;
    }
}
