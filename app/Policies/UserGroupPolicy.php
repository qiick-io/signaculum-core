<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\SuperAdminGuardService;
use Qiick\Access\EffectivePermissionResolver;

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
        private readonly EffectivePermissionResolver $permissionResolver,
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

    /**
     * The permission alone, checked before a trashed group is looked up, so a user without it
     * cannot tell an existing group id from a missing one (403 either way).
     */
    public function restoreAny(User $user): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanRestoreGroups->value);
    }

    public function restore(User $user, UserGroup $group): bool
    {
        return $this->restoreAny($user) && $this->canTouch($user, $group);
    }

    /**
     * The permission alone; see {@see self::restoreAny()}.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanForceDeleteGroups->value);
    }

    public function forceDelete(User $user, UserGroup $group): bool
    {
        return $this->forceDeleteAny($user) && $this->canTouch($user, $group);
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
