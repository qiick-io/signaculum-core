<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\SuperAdminGuardService;

/**
 * Admin decisions on instance user groups (externa-core abilities, Signaculum permissions).
 *
 * Record abilities keep groups that grant the super-admin role out of reach of
 * delegated administrators: without this, a group manager could add themselves
 * to such a group and inherit super-admin.
 */
final class UserGroupPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
        private readonly SuperAdminGuardService $superAdminGuard,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanShowGroups->value);
    }

    public function create(User $user): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanCreateGroups->value);
    }

    public function update(User $user, UserGroup $group): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanEditGroups->value)
            && $this->canTouch($user, $group);
    }

    public function delete(User $user, UserGroup $group): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanDeleteGroups->value)
            && $this->canTouch($user, $group);
    }

    public function restore(User $user, UserGroup $group): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanRestoreGroups->value)
            && $this->canTouch($user, $group);
    }

    public function forceDelete(User $user, UserGroup $group): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanForceDeleteGroups->value)
            && $this->canTouch($user, $group);
    }

    /**
     * Groups that grant super-admin are managed by super-admins only.
     */
    private function canTouch(User $user, UserGroup $group): bool
    {
        return $this->superAdminGuard->canManageSuperAdmins($user)
            || ! $this->superAdminGuard->hasSuperAdminGroup([$group->id]);
    }
}
