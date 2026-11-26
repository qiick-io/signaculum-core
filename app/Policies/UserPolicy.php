<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Authorizes IdP user administration. Super-admins are protected from delegated
 * admins; impersonation may only target accounts whose effective permissions are
 * a subset of the actor's and who hold no instance Spatie role.
 */
class UserPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissionResolver,
        private readonly SuperAdminGuardService $superAdminGuard,
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
        if (! $this->permissionResolver->hasPermission($actor, PermissionEnum::CanEditUsers->value)) {
            return false;
        }

        return ! $this->superAdminGuard->isProtectedFrom($actor, $target);
    }

    /**
     * Impersonation: permission required; cannot target oneself, an inactive user,
     * anyone with an instance role, or anyone whose effective permissions are not
     * a subset of the actor's.
     */
    public function impersonate(User $actor, User $target): bool
    {
        if (! $this->permissionResolver->hasPermission($actor, PermissionEnum::CanImpersonateUsers->value)) {
            return false;
        }

        if ($actor->is($target) || ! $target->is_active) {
            return false;
        }

        $targetRoleNames = $this->permissionResolver->roleNamesFor($target);

        foreach (RoleEnum::values() as $instanceRole) {
            if (in_array($instanceRole, $targetRoleNames, true)) {
                return false;
            }
        }

        $actorPermissions = $this->permissionResolver->permissionsFor($actor);
        $targetPermissions = $this->permissionResolver->permissionsFor($target);

        foreach ($targetPermissions as $permission) {
            if (! in_array($permission, $actorPermissions, true)) {
                return false;
            }
        }

        return true;
    }
}
