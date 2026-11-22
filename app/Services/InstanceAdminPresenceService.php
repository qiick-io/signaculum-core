<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Who the active instance admins are: the active users who may manage Admin API keys.
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
        return $this->lazyActiveInstanceAdmins()->first();
    }

    /**
     * Every active user who may manage Admin API keys, oldest account first.
     *
     * @return Collection<int, User>
     */
    public function activeInstanceAdmins(): Collection
    {
        return $this->lazyActiveInstanceAdmins()->values()->collect();
    }

    public function isActiveInstanceAdmin(User $user): bool
    {
        return $user->is_active
            && ! $user->trashed()
            && $this->permissionResolver->hasPermission($user, PermissionEnum::CanManageApiKeys->value);
    }

    /**
     * Streams the candidates, so a caller that needs only the first admin stops there.
     *
     * @return LazyCollection<int, User>
     */
    private function lazyActiveInstanceAdmins(): LazyCollection
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->lazy()
            ->filter(fn (User $candidate): bool => $this->isActiveInstanceAdmin($candidate));
    }
}
