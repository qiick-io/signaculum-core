<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\TokenFamilyData;
use App\Enums\TokenFamilyRootEnum;
use Closure;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * The token family of an authorization code or a device code: every access token
 * issued from the root or refreshed from one of its tokens carries the root's id
 * (`auth_code_id` or `device_code_id`), and each access token owns its refresh tokens.
 *
 * The root row is the family's lock: every refresh and every revocation lock it first
 * (the code exchange claims the code row), so they run one after the other within a
 * family and a revocation never misses a token minted in parallel (RFC 9700 §4.5,
 * §4.14.2). Device clients are usually public, so their rotated refresh tokens need the
 * same reuse detection (RFC 9700 §2.2.2).
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
    public function lock(TokenFamilyData $family): void
    {
        $family->root->rootModel()->newQuery()->whereKey($family->rootId)->lockForUpdate()->value('id');
    }

    /**
     * Put a newly issued access token in the family.
     */
    public function tag(string $accessTokenId, TokenFamilyData $family): void
    {
        Passport::token()->newQuery()->whereKey($accessTokenId)->update([$family->root->value => $family->rootId]);
    }

    /**
     * The family an access token belongs to; null for tokens from other grants.
     */
    public function familyOfAccessToken(mixed $accessTokenId): ?TokenFamilyData
    {
        if (! is_string($accessTokenId)) {
            return null;
        }

        $accessToken = Passport::token()->newQuery()->whereKey($accessTokenId)->first(array_column(TokenFamilyRootEnum::cases(), 'value'));

        foreach (TokenFamilyRootEnum::cases() as $root) {
            $rootId = $accessToken?->getAttribute($root->value);

            if (is_string($rootId)) {
                return new TokenFamilyData($root, $rootId);
            }
        }

        return null;
    }

    /**
     * Revoke every access token and refresh token of the family when a credential of it
     * was presented again after use (a leak). The check runs under the family lock, in a
     * transaction of its own: call this after the failed grant's transaction rolled back,
     * so a claim that grant made is undone and is not mistaken for a use.
     *
     * @param  Closure(): bool  $isReuseDetected  whether the presented code or refresh token was already used
     */
    public function revokeWhenReused(TokenFamilyData $family, Closure $isReuseDetected): void
    {
        DB::transaction(function () use ($family, $isReuseDetected): void {
            $this->lock($family);

            if (! $isReuseDetected()) {
                return;
            }

            $familyAccessTokenIds = Passport::token()->newQuery()
                ->where($family->root->value, $family->rootId)
                ->lockForUpdate()
                ->pluck('id');

            Passport::token()->newQuery()->whereKey($familyAccessTokenIds)->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $familyAccessTokenIds)->update(['revoked' => true]);
        }, self::TRANSACTION_ATTEMPTS);
    }
}
