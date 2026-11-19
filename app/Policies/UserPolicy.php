<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;

/**
 * Authorizes IdP user administration. Record-level rules still bind super-admins
 * (cannot impersonate another super-admin; last-super-admin guards live in actions).
 */
class UserPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanShowUsers->value);
    }

    public function view(User $actor, User $target): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanShowUsers->value);
    }

    public function create(User $actor): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanCreateUsers->value);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanEditUsers->value);
    }

    /**
     * Impersonation: needs the permission; cannot target a super-admin or oneself;
     * the target must be active.
     */
    public function impersonate(User $actor, User $target): bool
    {
        if (! $this->permissionResolver->hasPermission($actor, PermissionEnum::CanImpersonateUsers->value)) {
            return false;
        }

        if ($actor->is($target)) {
            return false;
        }

        if (in_array(RoleEnum::SuperAdmin->value, $this->permissionResolver->roleNamesFor($target), true)) {
            return false;
        }

        return (bool) $target->is_active;
    }
}
