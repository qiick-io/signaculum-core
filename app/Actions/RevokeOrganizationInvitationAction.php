<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Validation\ValidationException;

/**
 * Delete a pending organisation invitation (revoke).
 */
final class RevokeOrganizationInvitationAction
{
    public function execute(Organization $organization, OrganizationInvitation $invitation): void
    {
        if ($invitation->organization_id !== $organization->getKey()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation does not belong to the organisation.'),
            ]);
        }

        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => __('An accepted invitation cannot be revoked.'),
            ]);
        }

        $invitation->delete();
    }
}
