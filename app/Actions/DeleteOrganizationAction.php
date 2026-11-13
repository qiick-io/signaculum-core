<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;

/**
 * Delete an organisation and cascade its memberships.
 */
final class DeleteOrganizationAction
{
    public function execute(Organization $organization): void
    {
        $organization->delete();
    }
}
