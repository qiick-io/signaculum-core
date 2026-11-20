<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants or revokes the `super-admin` role (replaces the former `is_instance_admin` flag).
 * Only a super-admin may call this; refuses to remove the last active super-admin.
 */
final class SetInstanceAdminAction
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
        private readonly SuperAdminGuardService $superAdminGuard,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not manage super-admins
     * @throws ValidationException when revoking the last active super-admin
     */
    public function execute(User $user, bool $isInstanceAdmin, ?User $actor = null): void
    {
        $isTrustedConsole = $actor === null && app()->runningInConsole();

        if (! $isTrustedConsole && ! $this->superAdminGuard->canManageSuperAdmins($actor)) {
            throw new AuthorizationException;
        }

        $holdsSuperAdmin = $this->permissionResolver->isSuperAdmin($user);

        if (! $isInstanceAdmin && $holdsSuperAdmin && $this->superAdminGuard->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'user' => __('At least one super-admin must remain.'),
            ]);
        }

        if ($isInstanceAdmin) {
            $user->assignRole(RoleEnum::SuperAdmin->value);
        } else {
            $user->removeRole(RoleEnum::SuperAdmin->value);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->permissionResolver->forget($user);
    }
}
