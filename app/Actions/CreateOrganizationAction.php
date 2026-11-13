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
 */
final class CreateOrganizationAction
{
    public function execute(OrganizationData $organizationData, User $creator): Organization
    {
        return DB::transaction(function () use ($organizationData, $creator): Organization {
            $organization = Organization::query()->create([
                'name' => $organizationData->name,
                'slug' => $organizationData->slug,
            ]);

            OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $creator->getKey(),
                'roles' => [OrganizationRoleEnum::Owner->value],
            ]);

            return $organization->fresh(['memberships.user']) ?? $organization;
        });
    }
}
