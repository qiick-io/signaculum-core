<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Authorizes the instance activity log.
 */
class ActivityPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissionResolver,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanShowActivityLogs->value);
    }
}
