<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accept a pending invitation for the signed-in user whose email matches.
 */
final class AcceptOrganizationInvitationAction
{
    public function __construct(
        private readonly AddOrganizationMemberAction $addOrganizationMemberAction,
    ) {}

    public function execute(string $plainToken, User $user): OrganizationInvitation
    {
        return DB::transaction(function () use ($plainToken, $user): OrganizationInvitation {
            $invitation = OrganizationInvitation::query()
                ->with('organization')
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->accepted_at !== null) {
                throw ValidationException::withMessages([
                    'token' => __('This invitation is invalid or has already been used.'),
                ]);
            }

            if ($invitation->expires_at->lte(Date::now())) {
                throw ValidationException::withMessages([
                    'token' => __('This invitation has expired.'),
                ]);
            }

            if (Str::lower((string) $user->email) !== Str::lower($invitation->email)) {
                throw ValidationException::withMessages([
                    'token' => __('Sign in with the invited email address to accept this invitation.'),
                ]);
            }

            $organization = $invitation->organization;

            if ($organization === null) {
                throw ValidationException::withMessages([
                    'token' => __('This invitation is invalid or has already been used.'),
                ]);
            }

            $this->addOrganizationMemberAction->execute(
                $organization,
                $user,
                $invitation->roles,
            );

            $invitation->forceFill(['accepted_at' => Date::now()])->save();

            return $invitation->fresh(['organization']) ?? $invitation;
        });
    }
}
