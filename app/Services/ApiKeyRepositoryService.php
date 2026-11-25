<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\Contracts\ApiKeyRepository;
use Qiick\ApiKeys\CredentialKind;
use Qiick\ApiKeys\ExpiryNoticeClaimSql;

/**
 * Signaculum's `api_keys` table behind qiick/laravel-api-keys' {@see ApiKeyRepository}: the
 * package verifies tokens and runs the expiry notices, the product owns the rows.
 *
 * Side effects: `recordUsage()` writes `last_used_*` without touching `updated_at` (quiet save,
 * so no activity entry); the expiry methods write the claim lease and the notified stamp as
 * compare-and-set updates that also match the selected expiry and this run's own claim.
 */
final class ApiKeyRepositoryService implements ApiKeyRepository
{
    /**
     * The lease each claim of this run wrote, so that only its owner stamps or releases it: a
     * run whose lease ran out may finish after another run took the same notice over.
     *
     * @var array<string, string>
     */
    private array $claimedAtByPublicId = [];

    /**
     * The key itself, or the key whose previous prefix is still inside its grace window.
     *
     * @return iterable<ApiKeyRecord>
     */
    public function findCandidatesByPrefix(#[\SensitiveParameter] string $keyPrefix): iterable
    {
        return ApiKey::query()
            ->with(['ownerPrincipal', 'creator'])
            ->where('key_prefix', $keyPrefix)
            ->orWhere(fn ($previousPrefixQuery) => $previousPrefixQuery
                ->where('previous_key_prefix', $keyPrefix)
                ->where('previous_key_valid_until', '>', now()))
            ->get();
    }

    public function findByPublicId(string $publicId): ?ApiKeyRecord
    {
        return ApiKey::query()->where('uuid', $publicId)->first();
    }

    public function recordUsage(ApiKeyRecord $record, ?string $ip, ?string $userAgent, \DateTimeImmutable $usedAt): void
    {
        ApiKey::query()->whereKey($record->id())->firstOrFail()->forceFill([
            'last_used_at' => $usedAt,
            'last_used_ip' => $ip,
            'last_used_user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
        ])->saveQuietly();
    }

    /**
     * Keys still to expire inside the notice window: one that has already expired cannot be
     * rotated any more, so an "expires soon" notice would only mislead.
     *
     * @return iterable<ApiKeyRecord>
     */
    public function expiringUnnotified(\DateTimeImmutable $until): iterable
    {
        return ApiKey::query()
            ->with(['owner', 'creator'])
            ->where('credential_kind', CredentialKind::ApiKey->value)
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', $until)
            ->lazyById();
    }

    /**
     * The package's claim lease ({@see ExpiryNoticeClaimSql}), bound to the expiry
     * the notice was selected for: a rotation in between sets a new expiry, which earns its own
     * notice, so the old run can no longer claim, stamp or release the row.
     */
    public function claimExpiryNotice(ApiKeyRecord $record, \DateTimeImmutable $now, int $leaseSeconds): bool
    {
        $leaseCutoff = $now->sub(new \DateInterval('PT'.$leaseSeconds.'S'));
        $hasClaimed = $this->selectedNotice($record)
            ->where(fn (Builder $claimQuery) => $claimQuery
                ->whereNull('expiry_notice_claimed_at')
                ->orWhere('expiry_notice_claimed_at', '<', self::stored($leaseCutoff)))
            ->update(['expiry_notice_claimed_at' => self::stored($now)]) === 1;

        if ($hasClaimed) {
            $this->claimedAtByPublicId[$record->publicId()] = self::stored($now);
        }

        return $hasClaimed;
    }

    public function markExpiryNotified(ApiKeyRecord $record, \DateTimeImmutable $notifiedAt): void
    {
        $this->ownClaim($record)?->update(['expiry_notified_at' => self::stored($notifiedAt), 'expiry_notice_claimed_at' => null]);
        unset($this->claimedAtByPublicId[$record->publicId()]);
    }

    public function releaseExpiryNotice(ApiKeyRecord $record): void
    {
        $this->ownClaim($record)?->update(['expiry_notice_claimed_at' => null]);
        unset($this->claimedAtByPublicId[$record->publicId()]);
    }

    /**
     * Signaculum never issued a legacy token format.
     *
     * @return iterable<ApiKeyRecord>
     */
    public function findActiveLegacy(): iterable
    {
        return ApiKey::query()->where('token_version', 0)->whereNull('revoked_at')->lazyById();
    }

    /**
     * The row while it still awaits the notice of the expiry it was selected with.
     */
    private function selectedNotice(ApiKeyRecord $record): Builder
    {
        $expiresAt = $record->expiresAt();
        $noticeQuery = DB::table('api_keys')
            ->where('uuid', $record->publicId())
            ->whereNull('expiry_notified_at');

        return $expiresAt === null
            ? $noticeQuery->whereNull('expires_at')
            : $noticeQuery->where('expires_at', self::stored($expiresAt));
    }

    /**
     * {@see selectedNotice()} while it still holds the lease this run claimed; null without one.
     */
    private function ownClaim(ApiKeyRecord $record): ?Builder
    {
        $claimedAt = $this->claimedAtByPublicId[$record->publicId()] ?? null;

        return $claimedAt === null ? null : $this->selectedNotice($record)->where('expiry_notice_claimed_at', $claimedAt);
    }

    /**
     * The format the `datetime` casts store, in the application timezone (UTC).
     */
    private static function stored(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone(config()->string('app.timezone')))->format('Y-m-d H:i:s');
    }
}
