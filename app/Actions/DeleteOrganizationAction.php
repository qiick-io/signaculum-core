<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalDeactivationReasonEnum;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Offboards an organisation: deleting one is a job, never a database CASCADE (laravel-access
 * design §15.5, decision of 2026-11-23).
 *
 * In one transaction it deactivates every principal of the organisation as `offboarded`,
 * revokes the API keys they own, removes the memberships and pending invitations, and
 * soft-deletes the organisation. Principals, keys and activity rows stay as evidence;
 * `principals.organisation_id` is restrict, so the organisation row is never hard-deleted,
 * and its slug stays taken.
 *
 * Side effects: activity log `organization_deleted` with `organisation_id`, and `revoked` in
 * the `api-keys` log for each key it revokes; after commit, removes uploaded branding files
 * under `branding/organizations/{id}/`.
 */
final class DeleteOrganizationAction
{
    public function execute(Organization $organization): void
    {
        $organizationId = (string) $organization->getKey();
        $authenticatedUser = Auth::user();
        $actor = $authenticatedUser instanceof User ? $authenticatedUser : null;

        DB::transaction(function () use ($organization, $organizationId, $actor): void {
            /** @var list<string> $principalIds */
            $principalIds = Principal::query()
                ->where('organisation_id', $organizationId)
                ->pluck('id')
                ->all();

            Principal::query()
                ->whereKey($principalIds)
                ->whereNull('deactivated_at')
                ->update([
                    'deactivated_at' => Date::now(),
                    'deactivation_reason' => PrincipalDeactivationReasonEnum::Offboarded->value,
                ]);

            $this->revokeApiKeysOwnedBy($principalIds, $actor);

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
        });

        foreach ([$organization->logo_path, $organization->logo_dark_path] as $path) {
            if (is_string($path) && $path !== '') {
                Storage::disk('local')->delete($path);
            }
        }

        Storage::disk('local')->deleteDirectory('branding/organizations/'.$organizationId);
    }

    /**
     * A key owned by an offboarded principal stops at once, with an audit entry per key.
     *
     * @param  list<string>  $principalIds
     */
    private function revokeApiKeysOwnedBy(array $principalIds, ?User $actor): void
    {
        $apiKeys = ApiKey::query()
            ->whereIn('owner_principal_id', $principalIds)
            ->whereNull('revoked_at')
            ->get();

        foreach ($apiKeys as $apiKey) {
            $apiKey->forceFill([
                'revoked_at' => Date::now(),
                'previous_key_hash' => null,
                'previous_key_valid_until' => null,
            ])->save();

            $activity = activity('api-keys')
                ->performedOn($apiKey)
                ->event('revoked')
                ->withProperties(['name' => $apiKey->name, 'scopes' => $apiKey->scopes, 'reason' => 'owner']);

            if ($actor !== null) {
                $activity->causedBy($actor);
            }

            $activity->log('API key revoked');
        }
    }
}
