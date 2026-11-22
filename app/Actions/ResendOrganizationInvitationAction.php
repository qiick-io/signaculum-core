<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rotate the invitation token, extend expiry, and re-send the email.
 *
 * Side effects: queues {@see OrganizationInvitationNotification}.
 */
final class ResendOrganizationInvitationAction
{
    public function execute(
        Organization $organization,
        OrganizationInvitation $invitation,
        User $invitedBy,
    ): OrganizationInvitation {
        if ($invitation->organization_id !== $organization->getKey()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation does not belong to the organisation.'),
            ]);
        }

        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => __('An accepted invitation cannot be resent.'),
            ]);
        }

        $plainToken = Str::random(40);
        $ttlDays = (int) config('oidc.organization_invitation_ttl_days', 7);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $plainToken),
            'invited_by_user_id' => $invitedBy->getKey(),
            'expires_at' => Date::now()->addDays($ttlDays),
        ])->save();

        $acceptUrl = route('invitations.accept', ['token' => $plainToken]);

        Notification::route('mail', $invitation->email)
            ->notify(new OrganizationInvitationNotification(
                $organization,
                $acceptUrl,
                $invitedBy->locale,
            ));

        activity()
            ->performedOn($organization)
            ->causedBy($invitedBy)
            ->useLog('organizations')
            ->event('organization_invitation_resent')
            ->withProperties([
                'organisation_id' => $organization->getKey(),
                'email' => $invitation->email,
            ])
            ->log('Organisation invitation resent');

        return $invitation;
    }
}
