<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Authorizes instance settings (general, localization, security, appearance, LDAP, webhooks, email templates).
 */
class SettingPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissionResolver,
    ) {}

    public function update(User $user): bool
    {
        return $this->permissionResolver->hasPermission($user, PermissionEnum::CanManageSettings->value);
    }
}
