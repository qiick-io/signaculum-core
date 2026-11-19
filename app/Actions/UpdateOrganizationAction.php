<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OrganizationData;
use App\Models\Organization;
use App\Models\User;

/**
 * Update an organisation's name, slug and MFA requirement.
 *
 * Side effects: activity log when `is_mfa_required` changes (keys only, never secrets). Turning
 * the requirement on revokes the members' OAuth grants (RevokeOrganizationMemberTokensAction),
 * so tokens issued without a second factor do not outlive it (#163), then queues back-channel
 * logout for every member's open OIDC sessions (#174). Create never sets MFA (default false).
 */
final class UpdateOrganizationAction
{
    public function __construct(
        private readonly RevokeOrganizationMemberTokensAction $revokeOrganizationMemberTokensAction,
        private readonly DispatchOrganizationMfaBackChannelLogoutAction $dispatchOrganizationMfaBackChannelLogoutAction,
    ) {}

    public function execute(Organization $organization, OrganizationData $organizationData, ?User $actor = null): Organization
    {
        $previousIsMfaRequired = (bool) $organization->is_mfa_required;

        $organization->update([
            'name' => $organizationData->name,
            'slug' => $organizationData->slug,
            'is_mfa_required' => $organizationData->isMfaRequired,
        ]);

        if ($previousIsMfaRequired !== $organizationData->isMfaRequired) {
            $activity = activity()
                ->performedOn($organization)
                ->useLog('organizations')
                ->event('organization_mfa_requirement_updated')
                ->withProperties([
                    'is_mfa_required' => $organizationData->isMfaRequired,
                ]);

            if ($actor !== null) {
                $activity->causedBy($actor);
            }

            $activity->log('Organisation MFA requirement updated');
        }

        if (! $previousIsMfaRequired && $organizationData->isMfaRequired) {
            $this->revokeOrganizationMemberTokensAction->execute($organization, $actor);
            $this->dispatchOrganizationMfaBackChannelLogoutAction->execute($organization, $actor);
        }

        return $organization->refresh();
    }
}
