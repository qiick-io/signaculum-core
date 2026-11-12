<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Instance settings are edited by instance admins only. Organisation roles
 * (owner/admin/member/billing) never grant access to them.
 */
final class SettingPolicy
{
    public function update(User $user): bool
    {
        return (bool) $user->is_instance_admin;
    }
}
