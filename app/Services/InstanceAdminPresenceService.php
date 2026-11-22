<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;

/**
 * Whether the instance still has at least one active admin who may manage API keys.
 *
 * Admin REST API keys belong to the `instance_service` principal; they stay valid
 * only while this is true (decision 2026-11-22 / #229). Revocation stays manual.
 */
final class InstanceAdminPresenceService
{
    public function __construct(
        private readonly EffectivePermissionResolverService $permissionResolver,
    ) {}

    public function hasActiveInstanceAdmin(): bool
    {
        return $this->firstActiveInstanceAdmin() !== null;
    }

    public function firstActiveInstanceAdmin(): ?User
    {
        $candidates = User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->permissionResolver->hasPermission($candidate, PermissionEnum::CanManageApiKeys->value)) {
                return $candidate;
            }
        }

        return null;
    }
}
