<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * The token family of an authorization code: every access token issued from the code
 * or refreshed from one of its tokens carries the code's id (`auth_code_id`), and each
 * access token owns its refresh tokens.
 *
 * The code row is the family's lock: the code exchange, every refresh and every
 * revocation lock it first, so they run one after the other within a family and a
 * revocation never misses a token minted in parallel (RFC 9700 §4.5, §4.14.2).
 */
final class TokenFamilyService
{
    /**
     * Attempts of a token family transaction. MySQL and MariaDB (REPEATABLE READ) may pick
     * one as a deadlock victim against a parallel grant or purge; Laravel retries it on
     * such concurrency errors, so a reuse is still revoked instead of answering 500.
     */
    public const int TRANSACTION_ATTEMPTS = 3;

    /**
     * Lock the family root. Call it inside a transaction, before any other lock.
     */
    public function lock(string $authCodeId): void
    {
        Passport::authCode()->newQuery()->whereKey($authCodeId)->lockForUpdate()->value('id');
    }

    /**
     * The family an access token belongs to; null for tokens from other grants.
     */
    public function authCodeIdOfAccessToken(mixed $accessTokenId): ?string
    {
        if (! is_string($accessTokenId)) {
            return null;
        }

        $authCodeId = Passport::token()->newQuery()->whereKey($accessTokenId)->value('auth_code_id');

        return is_string($authCodeId) ? $authCodeId : null;
    }

    /**
     * Revoke every access token and refresh token of the family when a credential of it
     * was presented again after use (a leak). The check runs under the family lock, in a
     * transaction of its own: call this after the failed grant's transaction rolled back,
     * so a claim that grant made is undone and is not mistaken for a use.
     *
     * @param  Closure(): bool  $isReuseDetected  whether the presented code or refresh token was already used
     */
    public function revokeWhenReused(string $authCodeId, Closure $isReuseDetected): void
    {
        DB::transaction(function () use ($authCodeId, $isReuseDetected): void {
            $this->lock($authCodeId);

            if (! $isReuseDetected()) {
                return;
            }

            $familyAccessTokenIds = Passport::token()->newQuery()
                ->where('auth_code_id', $authCodeId)
                ->lockForUpdate()
                ->pluck('id');

            Passport::token()->newQuery()->whereKey($familyAccessTokenIds)->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $familyAccessTokenIds)->update(['revoked' => true]);
        }, self::TRANSACTION_ATTEMPTS);
    }
}
