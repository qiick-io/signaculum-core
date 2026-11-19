<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;

/**
 * Admin decisions on instance user groups (externa-core abilities, Signaculum permissions).
 */
final class UserGroupPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
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
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanEditGroups->value);
    }

    public function delete(User $user, UserGroup $group): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanDeleteGroups->value);
    }

    public function restore(User $user, UserGroup $group): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanRestoreGroups->value);
    }

    public function forceDelete(User $user, UserGroup $group): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanForceDeleteGroups->value);
    }
}
