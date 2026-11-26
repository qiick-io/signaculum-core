<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OrganizationData;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Create an organisation and attach the creating user as the first owner.
 *
 * Side effects: activity log `organization_created` with `organisation_id`.
 */
final class CreateOrganizationAction
{
    public function __construct(
        private readonly EnsureOrganizationMembershipPrincipalAction $ensureOrganizationMembershipPrincipalAction,
    ) {}

    public function execute(OrganizationData $organizationData, User $creator): Organization
    {
        return DB::transaction(function () use ($organizationData, $creator): Organization {
            // The user-row lock taken before the owner membership insert, as in
            // AddOrganizationMemberAction: the insert's foreign key takes a key-share lock on the
            // same row, and two creations by one user would each wait on the other's.
            User::query()->whereKey($creator->getKey())->lockForUpdate()->first();

            $organization = Organization::query()->create([
                'name' => $organizationData->name,
                'slug' => $organizationData->slug,
            ]);

            OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $creator->getKey(),
                'roles' => [OrganizationRoleEnum::Owner->value],
            ]);

            $this->ensureOrganizationMembershipPrincipalAction->execute($organization, $creator);

            activity()
                ->performedOn($organization)
                ->causedBy($creator)
                ->useLog('organizations')
                ->event('organization_created')
                ->withProperties([
                    'organisation_id' => $organization->getKey(),
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ])
                ->log('Organisation created');

            return $organization->fresh(['memberships.user']) ?? $organization;
        });
    }
}
