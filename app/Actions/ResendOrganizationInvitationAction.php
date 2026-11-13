<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rotate the token and expiry on a pending invitation, then email it again.
 */
final class ResendOrganizationInvitationAction
{
    public function __construct(
        private readonly InviteOrganizationMemberAction $inviteOrganizationMemberAction,
    ) {}

    /**
     * @return array{invitation: OrganizationInvitation, plain_token: string}
     */
    public function execute(Organization $organization, OrganizationInvitation $invitation): array
    {
        if ($invitation->organization_id !== $organization->getKey()) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation does not belong to the organisation.'),
            ]);
        }

        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => __('This invitation has already been accepted.'),
            ]);
        }

        return DB::transaction(function () use ($organization, $invitation): array {
            $plainToken = Str::random(40);

            $invitation->forceFill([
                'token_hash' => OrganizationInvitation::hashToken($plainToken),
                'expires_at' => now()->addHours((int) config('organizations.invitation_expires_hours', 72)),
            ])->save();

            $this->inviteOrganizationMemberAction->sendInvitationMail($organization, $invitation, $plainToken);

            return [
                'invitation' => $invitation->refresh(),
                'plain_token' => $plainToken,
            ];
        });
    }
}
