<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookEventEnum;
use App\Jobs\EndOrganizationMemberOidcSessionsJob;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\WebhookDispatcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Attach a user to an organisation with the given roles.
 *
 * Side effects: queues `organization.membership_changed` webhooks when subscribed. When the
 * organisation already requires MFA, also revokes that user's OAuth grants and queues
 * back-channel logout so a pre-membership token cannot keep refreshing without a step-up (#163).
 * Joining an organisation without MFA changes nothing about tokens.
 */
final class AddOrganizationMemberAction
{
    public function __construct(
        private readonly WebhookDispatcherService $webhookDispatcher,
        private readonly RevokeUserOidcGrantsAction $revokeUserOidcGrantsAction,
        private readonly EnsureOrganizationMembershipPrincipalAction $ensureOrganizationMembershipPrincipalAction,
    ) {}

    /**
     * @param  list<string>  $roles
     */
    public function execute(Organization $organization, User $user, array $roles): OrganizationMembership
    {
        $membership = DB::transaction(function () use ($organization, $user, $roles): OrganizationMembership {
            $alreadyMember = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->exists();

            if ($alreadyMember) {
                throw ValidationException::withMessages([
                    'user_id' => __('This user is already a member of the organisation.'),
                ]);
            }

            $createdMembership = OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'roles' => $roles,
            ]);

            $this->ensureOrganizationMembershipPrincipalAction->execute($organization, $user);

            return $createdMembership;
        });

        activity()
            ->performedOn($organization)
            ->useLog('organizations')
            ->event('organization_member_added')
            ->withProperties([
                'organisation_id' => $organization->getKey(),
                'user_id' => $user->subject,
                'roles' => $roles,
            ])
            ->log('Organisation member added');

        $this->webhookDispatcher->dispatch(WebhookEventEnum::OrganizationMembershipChanged, [
            'organization_id' => $organization->getKey(),
            'organization_slug' => $organization->slug,
            'user_id' => $user->subject,
            'action' => 'added',
            'roles' => $roles,
        ]);

        if ($organization->is_mfa_required) {
            $this->revokeUserOidcGrantsAction->execute($user);
            EndOrganizationMemberOidcSessionsJob::dispatch((int) $user->getKey());
        }

        return $membership;
    }
}
