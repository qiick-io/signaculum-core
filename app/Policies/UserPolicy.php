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

    public function view(User $actor, User $target): bool
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

    /**
     * Start a time-boxed session as another user (Admin → Users → Impersonate).
     * Instance admins cannot be impersonated; the actor cannot impersonate themselves;
     * the target must be active.
     */
    public function impersonate(User $actor, User $target): bool
    {
        if (! $actor->is_instance_admin) {
            return false;
        }

        if ($actor->is($target)) {
            return false;
        }

        if ($target->is_instance_admin) {
            return false;
        }

        return (bool) $target->is_active;
    }
}
