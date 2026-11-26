<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Enums\PrincipalTypeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Admin REST API keys belong to the instance_service principal. Any active admin with
 * can-manage-api-keys may create, rotate or revoke them; can-show-api-keys lists them.
 */
final class ApiKeyPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissionResolver,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->isActiveWithPermission($actor, PermissionEnum::CanShowApiKeys->value);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveWithPermission($actor, PermissionEnum::CanManageApiKeys->value);
    }

    public function update(User $actor, ApiKey $apiKey): bool
    {
        return $this->isActiveWithPermission($actor, PermissionEnum::CanManageApiKeys->value)
            && $this->isInstanceServiceKey($apiKey)
            && $apiKey->isActive();
    }

    public function delete(User $actor, ApiKey $apiKey): bool
    {
        return $this->isActiveWithPermission($actor, PermissionEnum::CanManageApiKeys->value)
            && $this->isInstanceServiceKey($apiKey);
    }

    private function isInstanceServiceKey(ApiKey $apiKey): bool
    {
        $ownerPrincipal = $apiKey->ownerPrincipal;

        return $ownerPrincipal !== null
            && $ownerPrincipal->type === PrincipalTypeEnum::InstanceService->value;
    }

    private function isActiveWithPermission(User $actor, string $permission): bool
    {
        return (bool) $actor->is_active
            && $this->permissionResolver->hasPermission($actor, $permission);
    }
}
