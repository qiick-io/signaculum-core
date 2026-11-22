<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Revoke every live OAuth grant of one user: access tokens, refresh tokens (including those
 * whose access token was already revoked) and unredeemed authorization codes.
 *
 * Used when a user joins an organisation that already requires MFA (#163) and when a password
 * change invalidates outstanding grants. Organisation-wide revocation on the MFA false → true
 * transition stays in {@see RevokeOrganizationMemberTokensAction}.
 *
 * @return array{
 *     revoked_access_token_count: int,
 *     revoked_refresh_token_count: int,
 *     revoked_authorization_code_count: int
 * }
 */
final class RevokeUserOidcGrantsAction
{
    /**
     * @return array{
     *     revoked_access_token_count: int,
     *     revoked_refresh_token_count: int,
     *     revoked_authorization_code_count: int
     * }
     */
    public function execute(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $userAccessTokenIds = Passport::token()->newQuery()
                ->where('user_id', $user->getKey())
                ->select('id');

            $revokedRefreshTokenCount = Passport::refreshToken()->newQuery()
                ->whereIn('access_token_id', $userAccessTokenIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $revokedAccessTokenCount = Passport::token()->newQuery()
                ->where('user_id', $user->getKey())
                ->where('revoked', false)
                ->update(['revoked' => true]);

            $revokedAuthorizationCodeCount = Passport::authCode()->newQuery()
                ->where('user_id', $user->getKey())
                ->where('revoked', false)
                ->update(['revoked' => true]);

            return [
                'revoked_access_token_count' => $revokedAccessTokenCount,
                'revoked_refresh_token_count' => $revokedRefreshTokenCount,
                'revoked_authorization_code_count' => $revokedAuthorizationCodeCount,
            ];
        });
    }
}
