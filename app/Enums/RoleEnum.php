<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Authorization\EffectivePermissionResolverService;

/**
 * Built-in instance roles. Organisation membership roles stay JSON on the pivot (declared
 * divergence: Spatie `teams => false`). Super-admin bypasses every permission check through
 * `Gate::before` via {@see EffectivePermissionResolverService}.
 */
enum RoleEnum: string
{
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Reader = 'reader';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Default permissions of the role. Super-admin also passes every check through `Gate::before`.
     * Admin receives every permission (former instance admins had full access). Reader is show-only.
     *
     * @return list<PermissionEnum>
     */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::SuperAdmin, self::Admin => PermissionEnum::cases(),
            self::Reader => array_values(array_filter(
                PermissionEnum::cases(),
                fn (PermissionEnum $permission): bool => $permission->isShowPermission(),
            )),
        };
    }
}
