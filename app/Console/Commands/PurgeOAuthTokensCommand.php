<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonInterface;
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
 * Here the code, its access tokens and their refresh tokens are deleted together, and
 * only once every one of them is purgeable: from then on no token of the family can be
 * used, so nothing is left to revoke. Rows outside a family (other grants, or tokens
 * whose code is already gone) are purged one by one, as Passport does.
 */
final class PurgeOAuthTokensCommand extends PurgeCommand
{
    private const int FAMILY_CHUNK_SIZE = 500;

    public function handle(): void
    {
        $isRevokedPurged = $this->option('revoked') || ! $this->option('expired');
        $expiredBefore = $this->option('expired') || ! $this->option('revoked')
            ? Date::now()->subHours((int) $this->option('hours'))
            : null;

        $this->purgeDeadTokenFamilies($isRevokedPurged, $expiredBefore);
        $this->purgeTokensOutsideFamilies($isRevokedPurged, $expiredBefore);

        $this->components->info(sprintf('Purged %s.', implode(' and ', array_filter([
            $isRevokedPurged ? 'revoked items' : null,
            $expiredBefore !== null ? "items expired for more than {$expiredBefore->longAbsoluteDiffForHumans()}" : null,
        ]))));
    }

    /**
     * Delete each code whose row and whole family are purgeable, with that family.
     */
    private function purgeDeadTokenFamilies(bool $isRevokedPurged, ?CarbonInterface $expiredBefore): void
    {
        $authCodesTable = Passport::authCode()->getTable();
        $accessTokensTable = Passport::token()->getTable();
        $refreshTokensTable = Passport::refreshToken()->getTable();

        Passport::authCode()->newQuery()
            ->where(fn (Builder $query) => $this->wherePurgeable($query, $authCodesTable, $isRevokedPurged, $expiredBefore))
            ->whereNotExists(fn (Builder $query) => $query
                ->from($accessTokensTable)
                ->whereColumn("{$accessTokensTable}.auth_code_id", "{$authCodesTable}.id")
                ->where(fn (Builder $query) => $this->whereUsable($query, $accessTokensTable, $isRevokedPurged, $expiredBefore)))
            ->whereNotExists(fn (Builder $query) => $query
                ->from($refreshTokensTable)
                ->join($accessTokensTable, "{$accessTokensTable}.id", '=', "{$refreshTokensTable}.access_token_id")
                ->whereColumn("{$accessTokensTable}.auth_code_id", "{$authCodesTable}.id")
                ->where(fn (Builder $query) => $this->whereUsable($query, $refreshTokensTable, $isRevokedPurged, $expiredBefore)))
            ->select("{$authCodesTable}.id")
            ->chunkById(self::FAMILY_CHUNK_SIZE, function (Collection $deadAuthCodes) use ($accessTokensTable): void {
                $deadAuthCodeIds = $deadAuthCodes->modelKeys();

                DB::transaction(function () use ($deadAuthCodeIds, $accessTokensTable): void {
                    Passport::refreshToken()->newQuery()
                        ->whereIn('access_token_id', fn (Builder $query) => $query
                            ->select('id')
                            ->from($accessTokensTable)
                            ->whereIn('auth_code_id', $deadAuthCodeIds))
                        ->delete();
                    Passport::token()->newQuery()->whereIn('auth_code_id', $deadAuthCodeIds)->delete();
                    Passport::authCode()->newQuery()->whereKey($deadAuthCodeIds)->delete();
                });
            }, "{$authCodesTable}.id", 'id');
    }

    /**
     * Passport's row-by-row purge, for the rows no surviving code holds together.
     */
    private function purgeTokensOutsideFamilies(bool $isRevokedPurged, ?CarbonInterface $expiredBefore): void
    {
        $authCodesTable = Passport::authCode()->getTable();
        $accessTokensTable = Passport::token()->getTable();
        $refreshTokensTable = Passport::refreshToken()->getTable();
        $now = Date::now();

        $isOutsideFamily = fn (Builder $query) => $query
            ->whereNull("{$accessTokensTable}.auth_code_id")
            ->orWhereNotExists(fn (Builder $query) => $query
                ->from($authCodesTable)
                ->whereColumn("{$authCodesTable}.id", "{$accessTokensTable}.auth_code_id"));

        Passport::token()->newQuery()
            ->where(fn (Builder $query) => $this->wherePurgeable($query, $accessTokensTable, $isRevokedPurged, $expiredBefore))
            ->where($isOutsideFamily)
            ->whereDoesntHave('refreshToken', fn ($query) => $query
                ->where('revoked', false)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now)))
            ->delete();

        Passport::refreshToken()->newQuery()
            ->where(fn (Builder $query) => $this->wherePurgeable($query, $refreshTokensTable, $isRevokedPurged, $expiredBefore))
            ->whereNotExists(fn (Builder $query) => $query
                ->from($accessTokensTable)
                ->join($authCodesTable, "{$authCodesTable}.id", '=', "{$accessTokensTable}.auth_code_id")
                ->whereColumn("{$accessTokensTable}.id", "{$refreshTokensTable}.access_token_id"))
            ->delete();

        if (Passport::$deviceCodeGrantEnabled) {
            Passport::deviceCode()->newQuery()
                ->where(fn (Builder $query) => $this->wherePurgeable($query, Passport::deviceCode()->getTable(), $isRevokedPurged, $expiredBefore))
                ->delete();
        }
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
     * The negation of wherePurgeable(), with a missing expiry counted as never expiring.
     */
    private function whereUsable(Builder $query, string $table, bool $isRevokedPurged, ?CarbonInterface $expiredBefore): void
    {
        if ($isRevokedPurged) {
            $query->where("{$table}.revoked", false);
        }

        if ($expiredBefore !== null) {
            $query->where(fn (Builder $query) => $query
                ->whereNull("{$table}.expires_at")
                ->orWhere("{$table}.expires_at", '>=', $expiredBefore));
        }
    }
}
