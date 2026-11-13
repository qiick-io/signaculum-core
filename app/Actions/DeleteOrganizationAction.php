<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * Delete an organisation and cascade its memberships.
 *
 * @side-effect Removes uploaded branding files under `branding/organizations/{id}/`.
 */
final class DeleteOrganizationAction
{
    public function execute(Organization $organization): void
    {
        foreach ([$organization->logo_path, $organization->logo_dark_path] as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        }

        Storage::disk('local')->deleteDirectory('branding/organizations/'.$organization->getKey());

        $organization->delete();
    }
}
