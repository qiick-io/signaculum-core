<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Create (or replace) a pending invitation and email the accept link.
 *
 * Side effects: queues {@see OrganizationInvitationNotification}.
 */
final class InviteOrganizationMemberAction
{
    /**
     * @param  list<string>  $roles
     */
    public function execute(Organization $organization, string $email, array $roles, User $invitedBy): OrganizationInvitation
    {
        $normalizedEmail = Str::lower(trim($email));

        return DB::transaction(function () use ($organization, $normalizedEmail, $roles, $invitedBy): OrganizationInvitation {
            $existingMember = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->whereHas('user', fn ($query) => $query->whereRaw('lower(email) = ?', [$normalizedEmail]))
                ->exists();

            if ($existingMember) {
                throw ValidationException::withMessages([
                    'email' => __('That person is already a member of this organisation.'),
                ]);
            }

            OrganizationInvitation::query()
                ->where('organization_id', $organization->getKey())
                ->whereRaw('lower(email) = ?', [$normalizedEmail])
                ->whereNull('accepted_at')
                ->delete();

            $plainToken = Str::random(40);
            $ttlDays = (int) config('oidc.organization_invitation_ttl_days', 7);

            $invitation = OrganizationInvitation::query()->create([
                'organization_id' => $organization->getKey(),
                'email' => $normalizedEmail,
                'roles' => $roles,
                'token_hash' => hash('sha256', $plainToken),
                'invited_by_user_id' => $invitedBy->getKey(),
                'expires_at' => Date::now()->addDays($ttlDays),
            ]);

            $acceptUrl = route('invitations.accept', ['token' => $plainToken]);

            Notification::route('mail', $normalizedEmail)
                ->notify(new OrganizationInvitationNotification(
                    $organization,
                    $acceptUrl,
                    $invitedBy->locale,
                ));

            activity()
                ->performedOn($organization)
                ->causedBy($invitedBy)
                ->useLog('organizations')
                ->event('organization_invitation_created')
                ->withProperties([
                    'organisation_id' => $organization->getKey(),
                    'email' => $normalizedEmail,
                    'roles' => $roles,
                ])
                ->log('Organisation invitation created');

            return $invitation;
        });
    }
}
