<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OrganizationData;
use App\Models\Organization;

/**
 * Update an organisation's name and slug.
 */
final class UpdateOrganizationAction
{
    public function execute(Organization $organization, OrganizationData $organizationData): Organization
    {
        $organization->update([
            'name' => $organizationData->name,
            'slug' => $organizationData->slug,
        ]);

        return $organization->refresh();
    }
}
