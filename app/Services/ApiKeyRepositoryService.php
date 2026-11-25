<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
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
 * so no activity entry); the expiry methods write the claim lease and the notified stamp with
 * the package's compare-and-set statements.
 */
final class ApiKeyRepositoryService implements ApiKeyRepository
{
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
     * @return iterable<ApiKeyRecord>
     */
    public function expiringUnnotified(\DateTimeImmutable $until): iterable
    {
        return ApiKey::query()
            ->with(['owner', 'creator'])
            ->where('credential_kind', CredentialKind::ApiKey->value)
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->where('expires_at', '<=', $until)
            ->lazyById();
    }

    public function markExpiryNotified(ApiKeyRecord $record, \DateTimeImmutable $notifiedAt): void
    {
        DB::update(ExpiryNoticeClaimSql::markNotified(), [self::stored($notifiedAt), $record->publicId()]);
    }

    public function claimExpiryNotice(ApiKeyRecord $record, \DateTimeImmutable $now, int $leaseSeconds): bool
    {
        $leaseCutoff = $now->sub(new \DateInterval('PT'.$leaseSeconds.'S'));

        return DB::update(ExpiryNoticeClaimSql::claim(), [self::stored($now), $record->publicId(), self::stored($leaseCutoff)]) === 1;
    }

    public function releaseExpiryNotice(ApiKeyRecord $record): void
    {
        DB::update(ExpiryNoticeClaimSql::release(), [$record->publicId()]);
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
     * The format the `datetime` casts store, in the application timezone (UTC).
     */
    private static function stored(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone(config()->string('app.timezone')))->format('Y-m-d H:i:s');
    }
}
