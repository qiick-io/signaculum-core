<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganizationRoleEnum;
use App\Exceptions\LastOrganizationOwnerException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Replace a member's roles. Refuses to remove the last owner.
 */
final class UpdateOrganizationMemberRolesAction
{
    /**
     * @param  list<string>  $roles
     */
    public function execute(Organization $organization, User $member, array $roles): OrganizationMembership
    {
        return DB::transaction(function () use ($organization, $member, $roles): OrganizationMembership {
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $member->getKey())
                ->firstOrFail();

            $hadOwner = $membership->hasRole(OrganizationRoleEnum::Owner);
            $willHaveOwner = in_array(OrganizationRoleEnum::Owner->value, $roles, true);

            if ($hadOwner && ! $willHaveOwner) {
                $otherOwners = OrganizationMembership::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('user_id', '!=', $member->getKey())
                    ->get()
                    ->filter(fn (OrganizationMembership $other): bool => $other->hasRole(OrganizationRoleEnum::Owner));

                if ($otherOwners->isEmpty()) {
                    throw new LastOrganizationOwnerException;
                }
            }

            $membership->update(['roles' => $roles]);

            return $membership->refresh();
        });
    }
}
