<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Instance admins manage every account on this IdP. Organisation roles never
 * grant user-admin access (same rule as {@see SettingPolicy}).
 */
final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return (bool) $actor->is_instance_admin;
    }

    public function create(User $actor): bool
    {
        return (bool) $actor->is_instance_admin;
    }

    public function update(User $actor, User $target): bool
    {
        return (bool) $actor->is_instance_admin;
    }
}
