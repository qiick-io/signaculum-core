<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Delete an organisation and cascade its memberships.
 *
 * Side effects: activity log `organization_deleted` with `organisation_id`;
 * removes uploaded branding files under `branding/organizations/{id}/`.
 */
final class DeleteOrganizationAction
{
    public function execute(Organization $organization): void
    {
        $organizationId = (string) $organization->getKey();
        $actor = Auth::user();

        $activity = activity()
            ->performedOn($organization)
            ->useLog('organizations')
            ->event('organization_deleted')
            ->withProperties([
                'organisation_id' => $organizationId,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ]);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }

        $activity->log('Organisation deleted');

        foreach ([$organization->logo_path, $organization->logo_dark_path] as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        }

        Storage::disk('local')->deleteDirectory('branding/organizations/'.$organizationId);

        $organization->delete();
    }
}
