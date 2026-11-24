<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TokenFamilyRootEnum;
use App\Services\TokenFamilyService;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Console\PurgeCommand;
use Laravel\Passport\Passport;

/**
 * `passport:purge` (same name, options and output) that keeps the evidence a token
 * family needs (see TokenFamilyService).
 *
 * Passport's purge deletes each revoked or expired row on its own. A redeemed code is
 * revoked at once and expires after 10 minutes, a rotated refresh token is revoked at
 * once, and an access token expires long before the refresh token that came with it:
 * purging those rows makes a replayed code or a reused refresh token look unknown
 * instead of used, so the family that is still alive is never revoked (RFC 6749
 * §4.1.2, RFC 9700 §4.5 and §4.14.2), and the refresh grant loses its lock row.
 *
 * A family's root is its authorization code or device code (TokenFamilyRootEnum). Here
 * the root, its access tokens and their refresh tokens are deleted together, and
 * only once no token of the family is usable: from then on nothing is left to revoke.
 * A token that expired before the retention cutoff counts as unusable whatever the
 * options, so `--revoked` alone also clears families that died by expiry.
 *
 * A live family keeps its root (the lock and the replay evidence) and the rotated pairs
 * whose refresh token could still be presented. A rotated pair goes once its refresh
 * token is revoked and expired before the cutoff: league refuses an expired refresh token
 * before any reuse check, so it no longer proves anything. The rows of an active family
 * are thus bounded by the refreshes of one refresh token lifetime plus the retention,
 * although the sliding refresh lifetime keeps the family itself alive.
 *
 * Rows outside a family (client credentials tokens, or tokens whose root is already
 * gone) are purged one by one, as Passport does.
 */
final class PurgeOAuthTokensCommand extends PurgeCommand
{
    private const int FAMILY_CHUNK_SIZE = 500;

    public function handle(): void
    {
        $retentionHours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        // A negative retention puts the cutoff in the future and would delete rotated refresh
        // tokens that can still be presented, so their reuse would go undetected.
        if ($retentionHours === false) {
            $this->fail('The --hours option must be a whole number of hours, 0 or more.');
        }

        $isRevokedPurged = $this->option('revoked') || ! $this->option('expired');
        $retentionCutoff = Date::now()->subHours($retentionHours);
        $expiredBefore = $this->option('expired') || ! $this->option('revoked') ? $retentionCutoff : null;

        $this->purgeDeadTokenFamilies($isRevokedPurged, $expiredBefore, $retentionCutoff);
        $this->purgeExpiredRotatedTokens($isRevokedPurged, $expiredBefore, $retentionCutoff);
        $this->purgeTokensOutsideFamilies($isRevokedPurged, $expiredBefore);

        $this->components->info(sprintf('Purged %s.', implode(' and ', array_filter([
            $isRevokedPurged ? 'revoked items' : null,
            $expiredBefore !== null ? "items expired for more than {$expiredBefore->longAbsoluteDiffForHumans()}" : null,
        ]))));
    }

    /**
     * Delete each root (code or device code) whose row and whole family are purgeable,
     * with that family.
     */
    private function purgeDeadTokenFamilies(bool $isRevokedPurged, ?CarbonInterface $expiredBefore, CarbonInterface $retentionCutoff): void
    {
        $accessTokensTable = Passport::token()->getTable();
        $refreshTokensTable = Passport::refreshToken()->getTable();

        foreach (TokenFamilyRootEnum::cases() as $root) {
            $rootsTable = $root->rootModel()->getTable();
            $rootColumn = "{$accessTokensTable}.{$root->value}";

            $root->rootModel()->newQuery()
                ->where(fn (Builder $query) => $this->wherePurgeable($query, $rootsTable, $isRevokedPurged, $expiredBefore))
                ->whereNotExists(fn (Builder $query) => $query
                    ->from($accessTokensTable)
                    ->whereColumn($rootColumn, "{$rootsTable}.id")
                    ->where(fn (Builder $query) => $this->whereUsable($query, $accessTokensTable, $isRevokedPurged, $retentionCutoff)))
                ->whereNotExists(fn (Builder $query) => $query
                    ->from($refreshTokensTable)
                    ->join($accessTokensTable, "{$accessTokensTable}.id", '=', "{$refreshTokensTable}.access_token_id")
                    ->whereColumn($rootColumn, "{$rootsTable}.id")
                    ->where(fn (Builder $query) => $this->whereUsable($query, $refreshTokensTable, $isRevokedPurged, $retentionCutoff)))
                ->select("{$rootsTable}.id")
                ->chunkById(self::FAMILY_CHUNK_SIZE, function (Collection $deadRoots) use ($root, $accessTokensTable): void {
                    $deadRootIds = $deadRoots->modelKeys();

                    DB::transaction(function () use ($root, $deadRootIds, $accessTokensTable): void {
                        // Root rows first, the lock order of TokenFamilyService.
                        $root->rootModel()->newQuery()->whereKey($deadRootIds)->lockForUpdate()->pluck('id');
                        Passport::refreshToken()->newQuery()
                            ->whereIn('access_token_id', fn (Builder $query) => $query
                                ->select('id')
                                ->from($accessTokensTable)
                                ->whereIn($root->value, $deadRootIds))
                            ->delete();
                        Passport::token()->newQuery()->whereIn($root->value, $deadRootIds)->delete();
                        $root->rootModel()->newQuery()->whereKey($deadRootIds)->delete();
                    }, TokenFamilyService::TRANSACTION_ATTEMPTS);
                }, "{$rootsTable}.id", 'id');
        }
    }

    /**
     * In live families: delete the refresh tokens that are revoked and expired before the
     * cutoff, then the family access tokens left without a refresh token that are
     * purgeable. Single statements, no family lock: none of these rows can be redeemed
     * any more.
     */
    private function purgeExpiredRotatedTokens(bool $isRevokedPurged, ?CarbonInterface $expiredBefore, CarbonInterface $retentionCutoff): void
    {
        $accessTokensTable = Passport::token()->getTable();
        $refreshTokensTable = Passport::refreshToken()->getTable();

        $this->retryingOnDeadlock(fn () => Passport::refreshToken()->newQuery()
            ->where('revoked', true)
            ->where('expires_at', '<', $retentionCutoff)
            ->delete());

        $this->retryingOnDeadlock(fn () => Passport::token()->newQuery()
            ->where(function (Builder $query) use ($accessTokensTable): void {
                foreach (TokenFamilyRootEnum::cases() as $root) {
                    $query->orWhereNotNull("{$accessTokensTable}.{$root->value}");
                }
            })
            ->where(fn (Builder $query) => $this->wherePurgeable($query, $accessTokensTable, $isRevokedPurged, $expiredBefore))
            ->whereNotExists(fn (Builder $query) => $query
                ->from($refreshTokensTable)
                ->whereColumn("{$refreshTokensTable}.access_token_id", "{$accessTokensTable}.id"))
            ->delete());
    }

    /**
     * Passport's row-by-row purge, for the tokens no surviving root holds together.
     */
    private function purgeTokensOutsideFamilies(bool $isRevokedPurged, ?CarbonInterface $expiredBefore): void
    {
        $accessTokensTable = Passport::token()->getTable();
        $refreshTokensTable = Passport::refreshToken()->getTable();
        $now = Date::now();

        $isOutsideFamily = function (Builder $query) use ($accessTokensTable): void {
            foreach (TokenFamilyRootEnum::cases() as $root) {
                $rootsTable = $root->rootModel()->getTable();

                $query->where(fn (Builder $query) => $query
                    ->whereNull("{$accessTokensTable}.{$root->value}")
                    ->orWhereNotExists(fn (Builder $query) => $query
                        ->from($rootsTable)
                        ->whereColumn("{$rootsTable}.id", "{$accessTokensTable}.{$root->value}")));
            }
        };

        $this->retryingOnDeadlock(fn () => Passport::token()->newQuery()
            ->where(fn (Builder $query) => $this->wherePurgeable($query, $accessTokensTable, $isRevokedPurged, $expiredBefore))
            ->where($isOutsideFamily)
            ->whereDoesntHave('refreshToken', fn ($query) => $query
                ->where('revoked', false)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now)))
            ->delete());

        $this->retryingOnDeadlock(fn () => Passport::refreshToken()->newQuery()
            ->where(fn (Builder $query) => $this->wherePurgeable($query, $refreshTokensTable, $isRevokedPurged, $expiredBefore))
            ->whereNotExists(fn (Builder $query) => $query
                ->from($accessTokensTable)
                ->whereColumn("{$accessTokensTable}.id", "{$refreshTokensTable}.access_token_id")
                ->whereNot($isOutsideFamily))
            ->delete());
    }

    /**
     * Run one purge statement in a transaction that Laravel retries when the database
     * picks it as a deadlock victim (MySQL and MariaDB may, scanning for `NOT EXISTS`).
     *
     * @param  Closure(): mixed  $statement
     */
    private function retryingOnDeadlock(Closure $statement): void
    {
        DB::transaction($statement, TokenFamilyService::TRANSACTION_ATTEMPTS);
    }

    /**
     * Passport's purge rule: revoked, or expired before the retention cutoff.
     */
    private function wherePurgeable(Builder $query, string $table, bool $isRevokedPurged, ?CarbonInterface $expiredBefore): void
    {
        if ($isRevokedPurged) {
            $query->orWhere("{$table}.revoked", true);
        }

        if ($expiredBefore !== null) {
            $query->orWhere("{$table}.expires_at", '<', $expiredBefore);
        }
    }

    /**
     * Not revoked (when revoked rows are purged) and not expired before the cutoff, with a
     * missing expiry counted as never expiring.
     */
    private function whereUsable(Builder $query, string $table, bool $isRevokedPurged, CarbonInterface $retentionCutoff): void
    {
        if ($isRevokedPurged) {
            $query->where("{$table}.revoked", false);
        }

        $query->where(fn (Builder $query) => $query
            ->whereNull("{$table}.expires_at")
            ->orWhere("{$table}.expires_at", '>=', $retentionCutoff));
    }
}
