<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;

/**
 * Admin REST API keys act as the admin who owns them, so only an active admin with
 * can-manage-api-keys manages keys, and only their own: one admin cannot rotate or revoke
 * another admin's key. `update` is rotation, allowed on active keys only.
 */
final class ApiKeyPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
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
            && $apiKey->owner_id === $actor->getKey()
            && $apiKey->isActive();
    }

    public function delete(User $actor, ApiKey $apiKey): bool
    {
        return $this->isActiveWithPermission($actor, PermissionEnum::CanManageApiKeys->value)
            && $apiKey->owner_id === $actor->getKey();
    }

    private function isActiveWithPermission(User $actor, string $permission): bool
    {
        return (bool) $actor->is_active
            && $this->permissionResolver->hasPermission($actor, $permission);
    }
}
