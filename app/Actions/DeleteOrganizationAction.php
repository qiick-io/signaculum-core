<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookEventEnum;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;
use App\Services\WebhookDispatcherService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\PrincipalDirectory;

/**
 * Offboards an organisation: deleting one is a job, never a database CASCADE (laravel-access
 * design §15.5, decision of 2026-11-23).
 *
 * In one transaction, under a lock on the organisation row (membership writes take the same
 * lock and refuse a deleted organisation, so no member can be added in between), it:
 * - revokes the members' OAuth grants and queues back-channel logout to their relying parties,
 *   as when the organisation turns MFA on, so members lose access at once;
 * - deactivates every principal of the organisation as `offboarded` (one already deactivated
 *   as `admin` keeps that reason: an administrator's decision outlives the offboarding);
 * - revokes the API keys they own;
 * - removes the memberships and pending invitations, and soft-deletes the organisation;
 * - stores one `organization.membership_changed` webhook delivery (`removed`) per member, as for
 *   a single removal, so a failure there rolls the offboarding back and a retry sends them all.
 *
 * Principals, keys and activity rows stay as evidence; `principals.organisation_id` is
 * restrict, so the organisation row is never hard-deleted, and its slug stays taken. Deleting
 * an organisation that is already deleted does nothing.
 *
 * Side effects: the activity entries of the two member actions, `organization_deleted` with
 * `organisation_id`; `PrincipalDeactivated` per principal, whose listener revokes their API keys
 * after commit; queues one `EndOrganizationMemberOidcSessionsJob` per member; after commit, the
 * webhooks' first-send job, and removes uploaded branding files under `branding/organizations/{id}/`.
 */
final class DeleteOrganizationAction
{
    public function __construct(
        private readonly RevokeOrganizationMemberTokensAction $revokeOrganizationMemberTokensAction,
        private readonly DispatchOrganizationMfaBackChannelLogoutAction $dispatchOrganizationMemberBackChannelLogoutAction,
        private readonly WebhookDispatcherService $webhookDispatcher,
        private readonly PrincipalDirectory $principalDirectory,
    ) {}

    public function execute(Organization $organization): void
    {
        $organizationId = (string) $organization->getKey();
        $authenticatedUser = Auth::user();
        $actor = $authenticatedUser instanceof User ? $authenticatedUser : null;

        /** @var list<string>|null $removedMemberSubjects */
        $removedMemberSubjects = DB::transaction(function () use ($organization, $organizationId, $actor): ?array {
            $isLive = Organization::query()->whereKey($organizationId)->lockForUpdate()->exists();

            if (! $isLive) {
                return null;
            }

            // Both read the memberships, so they run before the memberships are removed.
            $this->revokeOrganizationMemberTokensAction->execute($organization, $actor);
            $this->dispatchOrganizationMemberBackChannelLogoutAction->execute($organization, $actor);

            /** @var list<string> $removedMemberSubjects */
            $removedMemberSubjects = User::query()
                ->whereIn('id', OrganizationMembership::query()->where('organization_id', $organizationId)->select('user_id'))
                ->pluck('subject')
                ->all();

            // Through the directory: principals they own follow, and PrincipalDeactivated revokes
            // their API keys after commit (RevokeDeactivatedPrincipalApiKeysListener).
            Principal::query()
                ->where('organisation_id', $organizationId)
                ->whereNull('deactivated_at')
                ->get()
                ->each(fn (Principal $principal) => $this->principalDirectory->deactivate($principal, DeactivationReason::Offboarded));

            OrganizationMembership::query()->where('organization_id', $organizationId)->delete();
            OrganizationInvitation::query()->where('organization_id', $organizationId)->delete();

            $activity = activity()
                ->performedOn($organization)
                ->useLog('organizations')
                ->event('organization_deleted')
                ->withProperties([
                    'organisation_id' => $organizationId,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ]);

            if ($actor !== null) {
                $activity->causedBy($actor);
            }

            $activity->log('Organisation deleted');

            $organization->delete();

            // Stored in this transaction (an outbox): the offboarding and its events commit or
            // roll back together; the first-send job is queued after commit.
            foreach ($removedMemberSubjects as $removedMemberSubject) {
                $this->webhookDispatcher->dispatch(WebhookEventEnum::OrganizationMembershipChanged, [
                    'organization_id' => $organizationId,
                    'organization_slug' => $organization->slug,
                    'user_id' => $removedMemberSubject,
                    'action' => 'removed',
                    'roles' => [],
                ]);
            }

            return $removedMemberSubjects;
        });

        if ($removedMemberSubjects === null) {
            return;
        }

        foreach ([$organization->logo_path, $organization->logo_dark_path] as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        }

        Storage::disk('local')->deleteDirectory('branding/organizations/'.$organizationId);
    }
}
