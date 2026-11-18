<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Revoke every live OAuth grant of an organisation's members when the organisation starts
 * requiring MFA (#163).
 *
 * The MFA gate runs only on `/oauth/authorize`, and access and refresh tokens carry no record
 * of how their session authenticated, so a grant issued before the requirement would keep
 * working (and keep refreshing) for its whole lifetime. Revoking at the transition makes the
 * members' clients re-run the authorization code flow, where the gate steps them up.
 * Unredeemed authorization codes are revoked too: a code issued a minute before the switch
 * would otherwise still be exchanged for a fresh token pair.
 *
 * Every grant of a member is revoked, not only the ones issued without a second factor: the
 * tokens do not record which those are. Tokens of users outside the organisation stay valid.
 *
 * Side effects: updates `oauth_auth_codes`, `oauth_access_tokens` and `oauth_refresh_tokens`;
 * writes an `organization_member_tokens_revoked` activity entry with the counts (keys only,
 * never token values).
 */
final class RevokeOrganizationMemberTokensAction
{
    public function execute(Organization $organization, ?User $actor = null): void
    {
        $memberUserIds = OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->select('user_id');

        $revokedCounts = DB::transaction(function () use ($memberUserIds): array {
            // Every member access token, revoked or not: a live refresh token of a revoked
            // access token still mints a new pair.
            $memberAccessTokenIds = Passport::token()->newQuery()
                ->whereIn('user_id', $memberUserIds)
                ->select('id');

            $revokedRefreshTokenCount = Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', $memberAccessTokenIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $revokedAccessTokenCount = Passport::token()->newQuery()
                ->whereIn('user_id', $memberUserIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $revokedAuthorizationCodeCount = Passport::authCode()->newQuery()
                ->whereIn('user_id', $memberUserIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            return [
                'revoked_access_token_count' => $revokedAccessTokenCount,
                'revoked_refresh_token_count' => $revokedRefreshTokenCount,
                'revoked_authorization_code_count' => $revokedAuthorizationCodeCount,
            ];
        });

        $activity = activity()
            ->performedOn($organization)
            ->useLog('organizations')
            ->event('organization_member_tokens_revoked')
            ->withProperties($revokedCounts);

        if ($actor !== null) {
            $activity->causedBy($actor);
        }

        $activity->log('Organisation member tokens revoked');
    }
}
