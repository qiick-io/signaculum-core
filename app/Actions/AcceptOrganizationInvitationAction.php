<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        if ($invitation === null || $invitation->isExpired() || $invitation->accepted_at !== null) {
            throw new NotFoundHttpException(__('This invitation is invalid or has expired.'));
        }

        if (Str::lower($user->email) !== Str::lower($invitation->email)) {
            throw new AccessDeniedHttpException(__('Sign in with the invited email address to accept.'));
        }

        return DB::transaction(function () use ($invitation, $user): OrganizationInvitation {
            $organization = $invitation->organization()->firstOrFail();

            $alreadyMember = OrganizationMembership::query()
                ->where('organization_id', $invitation->organization_id)
                ->where('user_id', $user->getKey())
                ->exists();

            if (! $alreadyMember) {
                $this->addOrganizationMemberAction->execute(
                    $organization,
                    $user,
                    $invitation->roles,
                );
            }

            // Clear the hash so the plaintext token can never match again (nullable unique).
            $invitation->forceFill([
                'accepted_at' => now(),
                'token_hash' => null,
            ])->save();

            return $invitation->refresh();
        });
    }

    public function findPendingOrFail(string $plainToken): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()
            ->with('organization:id,name,slug')
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        if ($invitation === null || $invitation->token_hash === null || $invitation->isExpired() || $invitation->accepted_at !== null) {
            throw new NotFoundHttpException(__('This invitation is invalid or has expired.'));
        }

        return $invitation;
    }
}
