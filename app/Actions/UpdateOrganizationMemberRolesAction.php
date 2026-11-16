<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\OrganizationRoleEnum;
use App\Enums\WebhookEventEnum;
use App\Exceptions\LastOrganizationOwnerException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\WebhookDispatcherService;
use Illuminate\Support\Facades\DB;

/**
 * Replace a member's roles. Refuses to remove the last owner.
 *
 * Side effects: queues `organization.membership_changed` webhooks when subscribed.
 */
final class UpdateOrganizationMemberRolesAction
{
    public function __construct(
        private readonly WebhookDispatcherService $webhookDispatcher,
    ) {}

    /**
     * @param  list<string>  $roles
     */
    public function execute(Organization $organization, User $member, array $roles): OrganizationMembership
    {
        $membership = DB::transaction(function () use ($organization, $member, $roles): OrganizationMembership {
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $member->getKey())
                ->firstOrFail();

            $hadOwner = $membership->hasRole(OrganizationRoleEnum::Owner);
            $willHaveOwner = in_array(OrganizationRoleEnum::Owner->value, $roles, true);

            if ($hadOwner && ! $willHaveOwner) {
                $otherOwners = OrganizationMembership::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('user_id', '!=', $member->getKey())
                    ->get()
                    ->filter(fn (OrganizationMembership $other): bool => $other->hasRole(OrganizationRoleEnum::Owner));

                if ($otherOwners->isEmpty()) {
                    throw new LastOrganizationOwnerException;
                }
            }

            $membership->update(['roles' => $roles]);

            return $membership->refresh();
        });

        $this->webhookDispatcher->dispatch(WebhookEventEnum::OrganizationMembershipChanged, [
            'organization_id' => $organization->getKey(),
            'organization_slug' => $organization->slug,
            'user_id' => $member->subject,
            'action' => 'roles_updated',
            'roles' => $roles,
        ]);

        return $membership;
    }
}
