<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PermissionEnum;
use App\Models\User;
use Laravel\Passport\Client;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Authorizes OAuth application (Passport client) administration.
 */
class ApplicationPolicy
{
    public function __construct(
        private readonly EffectivePermissionResolver $permissionResolver,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanShowApplications->value);
    }

    public function create(User $actor): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanCreateApplications->value);
    }

    public function update(User $actor, Client $application): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanEditApplications->value);
    }

    public function delete(User $actor, Client $application): bool
    {
        return $this->permissionResolver->hasPermission($actor, PermissionEnum::CanDeleteApplications->value);
    }
}
