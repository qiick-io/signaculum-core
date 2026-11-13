<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attach a user to an organisation with the given roles.
 */
final class AddOrganizationMemberAction
{
    /**
     * @param  list<string>  $roles
     */
    public function execute(Organization $organization, User $user, array $roles): OrganizationMembership
    {
        return DB::transaction(function () use ($organization, $user, $roles): OrganizationMembership {
            $alreadyMember = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->exists();

            if ($alreadyMember) {
                throw ValidationException::withMessages([
                    'user_id' => __('This user is already a member of the organisation.'),
                ]);
            }

            return OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'roles' => $roles,
            ]);
        });
    }
}
