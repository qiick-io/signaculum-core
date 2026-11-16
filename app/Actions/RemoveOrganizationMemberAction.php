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
 * Remove a member from an organisation. Refuses to remove the last owner.
 *
 * Side effects: queues `organization.membership_changed` webhooks when subscribed.
 */
final class RemoveOrganizationMemberAction
{
    public function __construct(
        private readonly WebhookDispatcherService $webhookDispatcher,
    ) {}

    public function execute(Organization $organization, User $member): void
    {
        DB::transaction(function () use ($organization, $member): void {
            $membership = OrganizationMembership::query()
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $member->getKey())
                ->firstOrFail();

            if ($membership->hasRole(OrganizationRoleEnum::Owner)) {
                $otherOwners = OrganizationMembership::query()
                    ->where('organization_id', $organization->getKey())
                    ->where('user_id', '!=', $member->getKey())
                    ->get()
                    ->filter(fn (OrganizationMembership $other): bool => $other->hasRole(OrganizationRoleEnum::Owner));

                if ($otherOwners->isEmpty()) {
                    throw new LastOrganizationOwnerException;
                }
            }

            $membership->delete();
        });

        $this->webhookDispatcher->dispatch(WebhookEventEnum::OrganizationMembershipChanged, [
            'organization_id' => $organization->getKey(),
            'organization_slug' => $organization->slug,
            'user_id' => $member->subject,
            'action' => 'removed',
            'roles' => [],
        ]);
    }
}
