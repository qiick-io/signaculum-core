<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\EndOrganizationMemberOidcSessionsJob;
use App\Models\ClientSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * After an organisation turns MFA on (#174), or while it is being deleted (#289), queue one
 * job per member that ends their open OIDC sessions and fans out back-channel logout to
 * clients with a URI.
 *
 * Runs on the MFA false → true transition (UpdateOrganizationAction) and inside the
 * offboarding of DeleteOrganizationAction, before the memberships are removed.
 * Organisation creation never sets `is_mfa_required` (DB default false; create form has
 * no MFA field), so create-with-true is out of scope here. Owner is included. The
 * acting admin's Signaculum browser session is left alone; only RP sessions are ended.
 *
 * Side effects: queues `EndOrganizationMemberOidcSessionsJob` (never runs the fan-out
 * inside the HTTP request); writes `organization_member_backchannel_logout_dispatched`
 * with `member_count` and `logout_token_count` (keys only).
 */
final class DispatchOrganizationMfaBackChannelLogoutAction
{
    public function execute(Organization $organization, ?User $actor = null): void
    {
        $memberUserIds = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->pluck('user_id')
            ->map(fn (mixed $userId): int => (int) $userId)
            ->all();

        $memberCount = count($memberUserIds);

        $logoutTokenCount = $memberCount === 0
            ? 0
            : ClientSession::query()
                ->whereIn('user_id', $memberUserIds)
                ->whereNull('ended_at')
                ->whereHas(
                    'client',
                    fn ($clientQuery) => $clientQuery
                        ->whereNotNull('backchannel_logout_uri')
                        ->where('backchannel_logout_uri', '!=', ''),
                )
                ->count();

        foreach ($memberUserIds as $memberUserId) {
            EndOrganizationMemberOidcSessionsJob::dispatch($memberUserId);
        }

        $activity = activity()
            ->performedOn($organization)
            ->useLog('organizations')
            ->event('organization_member_backchannel_logout_dispatched')
            ->withProperties([
                'member_count' => $memberCount,
                'logout_token_count' => $logoutTokenCount,
            ]);

        if ($actor !== null) {
            $activity->causedBy($actor);
        }

        $activity->log('Organisation member back-channel logout dispatched');
    }
}
