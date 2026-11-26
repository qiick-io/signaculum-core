<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Enums\RoleEnum;
use Qiick\Access\Contracts\RoleCatalog;

/**
 * Signaculum's role names for qiick/laravel-access ({@see RoleCatalog}).
 */
final class AccessRoleCatalogService implements RoleCatalog
{
    public function superAdminRoleName(): string
    {
        return RoleEnum::SuperAdmin->value;
    }
}
