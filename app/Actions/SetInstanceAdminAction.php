<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Grants or revokes the `super-admin` role (replaces the former `is_instance_admin` flag).
 * Refuses to remove the last super-admin so the instance cannot lock itself out.
 */
final class SetInstanceAdminAction
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
    ) {}

    /**
     * @throws ValidationException when revoking the last super-admin
     */
    public function execute(User $user, bool $isInstanceAdmin): void
    {
        $holdsSuperAdmin = $this->permissionResolver->isSuperAdmin($user);

        if (! $isInstanceAdmin && $holdsSuperAdmin && $this->isLastSuperAdmin($user)) {
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

    private function isLastSuperAdmin(User $user): bool
    {
        $superAdmins = User::role(RoleEnum::SuperAdmin->value)->get();

        return $superAdmins->count() === 1 && $superAdmins->first()?->is($user);
    }
}
