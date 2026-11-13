<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OrganizationInvitationData;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create a pending organisation invitation and email the opaque accept URL.
 */
final class InviteOrganizationMemberAction
{
    /**
     * @return array{invitation: OrganizationInvitation, plain_token: string}
     */
    public function execute(
        Organization $organization,
        OrganizationInvitationData $invitationData,
        User $invitedBy,
    ): array {
        return DB::transaction(function () use ($organization, $invitationData, $invitedBy): array {
            $email = Str::lower($invitationData->email);

            $alreadyMember = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$email]))
                ->exists();

            if ($alreadyMember) {
                throw ValidationException::withMessages([
                    'email' => __('This email already belongs to a member of the organisation.'),
                ]);
            }

            $pendingExists = OrganizationInvitation::query()
                ->where('organization_id', $organization->getKey())
                ->whereRaw('lower(email) = ?', [$email])
                ->whereNull('accepted_at')
                ->exists();

            if ($pendingExists) {
                throw ValidationException::withMessages([
                    'email' => __('A pending invitation already exists for this email.'),
                ]);
            }

            $plainToken = Str::random(40);

            $invitation = OrganizationInvitation::query()->create([
                'organization_id' => $organization->getKey(),
                'email' => $email,
                'roles' => $invitationData->roles,
                'token_hash' => OrganizationInvitation::hashToken($plainToken),
                'invited_by_user_id' => $invitedBy->getKey(),
                'expires_at' => now()->addHours((int) config('organizations.invitation_expires_hours', 72)),
            ]);

            $this->sendInvitationMail($organization, $invitation, $plainToken);

            return [
                'invitation' => $invitation,
                'plain_token' => $plainToken,
            ];
        });
    }

    public function sendInvitationMail(
        Organization $organization,
        OrganizationInvitation $invitation,
        string $plainToken,
    ): void {
        $acceptUrl = route('invitations.accept', ['token' => $plainToken]);

        Notification::route('mail', $invitation->email)
            ->notify(new OrganizationInvitationNotification($organization, $plainToken, $acceptUrl));
    }
}
