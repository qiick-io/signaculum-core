<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * Organisation access: any member may view; owners/admins may manage members and settings.
 */
final class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->membershipFor($user, $organization) !== null || (bool) $user->is_instance_admin;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $this->isOwner($user, $organization) || (bool) $user->is_instance_admin;
    }

    public function manageMembers(User $user, Organization $organization): bool
    {
        return $this->canManage($user, $organization);
    }

    private function canManage(User $user, Organization $organization): bool
    {
        if ((bool) $user->is_instance_admin) {
            return true;
        }

        $membership = $this->membershipFor($user, $organization);

        if ($membership === null) {
            return false;
        }

        return $membership->hasRole(OrganizationRoleEnum::Owner)
            || $membership->hasRole(OrganizationRoleEnum::Admin);
    }

    private function isOwner(User $user, Organization $organization): bool
    {
        if ((bool) $user->is_instance_admin) {
            return true;
        }

        $membership = $this->membershipFor($user, $organization);

        return $membership !== null && $membership->hasRole(OrganizationRoleEnum::Owner);
    }

    private function membershipFor(User $user, Organization $organization): ?OrganizationMembership
    {
        return OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }
}
