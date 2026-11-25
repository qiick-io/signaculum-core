<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyScopeEnum;
use App\Services\ApiKeyScopeVocabularyService;
use App\Traits\LogsApplicationActivityTrait;
use App\Traits\RejectsInvalidUuidRouteKeysTrait;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\CredentialKind;
use Qiick\ApiKeys\IpAllowlist;
use Qiick\ApiKeys\RevocationReason;
use Qiick\ApiKeys\Scope;
use Qiick\ApiKeys\ScopeSet;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Admin REST API key, on the column contract of qiick/laravel-api-keys
 * (`docs/reference-schema.md`): looked up by its public `key_prefix`, the full token stored only
 * as a SHA-256 hash, and the secret replaced by the last rotation kept in `previous_key_hash`
 * until `previous_key_valid_until`. The package's `TokenCodec` issues and verifies tokens; this
 * model is the product-owned {@see ApiKeyRecord} it reads. The plain-text token is never an
 * attribute.
 *
 * Nothing is mass assignable: every write names its columns through `forceFill()` in an action,
 * so a request payload can never reach a hash, an owner or an expiry by accident.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string $key_prefix
 * @property string $key_hash
 * @property int $token_version
 * @property string $hash_algorithm
 * @property string|null $previous_key_prefix
 * @property string|null $previous_key_hash
 * @property Carbon|null $previous_key_valid_until
 * @property string $credential_kind
 * @property string|null $oauth_client_id
 * @property int $owner_id
 * @property string $owner_principal_id
 * @property int|null $created_by
 * @property list<string>|null $scopes
 * @property list<string>|null $ip_allowlist
 * @property array<string, mixed>|null $restrictions
 * @property int|null $rate_limit_per_minute
 * @property int|null $rate_limit_per_day
 * @property int|null $rate_limit_per_month
 * @property array<string, mixed>|null $metadata
 * @property Carbon $expires_at
 * @property Carbon|null $expiry_notified_at
 * @property Carbon|null $expiry_notice_claimed_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property string|null $last_used_user_agent
 * @property Carbon|null $last_rotated_at
 * @property Carbon|null $exposed_at
 * @property string|null $exposure_source
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $revoked_reason
 * @property string|null $revocation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Hidden(['key_hash', 'previous_key_hash'])]
class ApiKey extends Model implements ApiKeyRecord
{
    use HasUuids, LogsApplicationActivityTrait, RejectsInvalidUuidRouteKeysTrait;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Fleet principal that owns the key (the singleton `instance_service` for Admin API keys).
     *
     * @return BelongsTo<Principal, $this>
     */
    public function ownerPrincipal(): BelongsTo
    {
        return $this->belongsTo(Principal::class, 'owner_principal_id');
    }

    /**
     * Who issued the key; kept apart from {@see ownerPrincipal()}.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return ! $this->expires_at->isFuture();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * The stored scopes parsed fail-closed against this product's vocabulary, highest per
     * resource; empty when they do not parse (missing, malformed or no longer known): such a key
     * may do nothing.
     */
    public function grantedScopes(): ScopeSet
    {
        return ScopeSet::parseStored($this->getAttribute('scopes'), app(ApiKeyScopeVocabularyService::class));
    }

    /**
     * `write` implies `read` on the same resource ({@see Scope::satisfies()}).
     */
    public function hasScope(ApiKeyScopeEnum|string $scope): bool
    {
        $requiredScope = Scope::tryFromString($scope instanceof ApiKeyScopeEnum ? $scope->value : $scope);

        return $requiredScope !== null && $this->grantedScopes()->allows($requiredScope);
    }

    /**
     * The package's allowlist rule on {@see rawIpAllowlist()}: no allowlist or an empty one allows
     * every address, one that does not parse allows none (an unreadable restriction is not "no
     * restriction").
     */
    public function allowsIp(string $ipAddress): bool
    {
        $storedAllowlist = $this->rawIpAllowlist();

        try {
            $allowlist = is_string($storedAllowlist) ? json_decode($storedAllowlist, true, flags: JSON_THROW_ON_ERROR) : $storedAllowlist;
        } catch (\JsonException) {
            return false;
        }

        return app(IpAllowlist::class)->allows($ipAddress, $allowlist);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->logExcept(['key_hash', 'previous_key_hash']);
    }

    public function id(): string|int
    {
        return (int) $this->getKey();
    }

    public function publicId(): string
    {
        return $this->uuid;
    }

    public function keyPrefix(): string
    {
        return $this->key_prefix;
    }

    public function keyHash(): string
    {
        return $this->key_hash;
    }

    public function tokenVersion(): int
    {
        return $this->token_version;
    }

    public function hashAlgorithm(): string
    {
        return $this->hash_algorithm;
    }

    public function previousKeyPrefix(): ?string
    {
        return $this->previous_key_prefix;
    }

    public function previousKeyHash(): ?string
    {
        return $this->previous_key_hash;
    }

    public function previousKeyValidUntil(): ?\DateTimeImmutable
    {
        return $this->previous_key_valid_until?->toDateTimeImmutable();
    }

    public function credentialKind(): CredentialKind
    {
        // An unknown stored value reads as an OAuth grant, which the authenticator refuses.
        return CredentialKind::tryFrom($this->credential_kind) ?? CredentialKind::OauthGrant;
    }

    public function oauthClientId(): ?string
    {
        return $this->oauth_client_id;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata ?? [];
    }

    /**
     * The JSON columns as stored (qiick/laravel-api-keys 0.3.0-beta.2): the package decodes them
     * and fails closed on a value that is not valid JSON, which the `array` cast would read as null.
     */
    public function rawScopes(): mixed
    {
        return $this->getRawOriginal('scopes');
    }

    /**
     * Only SQL NULL means "no allowlist" here. The package also reads a stored JSON `null` as
     * unrestricted; Signaculum never writes one, so a row holding it was not written by the
     * form (repair, import) and is handed on as `false`, which the package denies.
     */
    public function rawIpAllowlist(): mixed
    {
        $storedAllowlist = $this->getRawOriginal('ip_allowlist');

        return is_string($storedAllowlist) && json_validate($storedAllowlist) && json_decode($storedAllowlist) === null
            ? false
            : $storedAllowlist;
    }

    public function rawRestrictions(): mixed
    {
        return $this->getRawOriginal('restrictions');
    }

    /**
     * A stored limit of zero or less is not "unlimited": it falls back to
     * `api-keys.default_rate_limit_per_minute`, so a bad value never removes the limit.
     */
    public function rateLimitPerMinute(): ?int
    {
        return $this->rate_limit_per_minute !== null && $this->rate_limit_per_minute > 0 ? $this->rate_limit_per_minute : null;
    }

    public function rateLimitPerDay(): ?int
    {
        return $this->rate_limit_per_day;
    }

    public function rateLimitPerMonth(): ?int
    {
        return $this->rate_limit_per_month;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expires_at->toDateTimeImmutable();
    }

    public function expiryNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->expiry_notified_at?->toDateTimeImmutable();
    }

    public function expiryNoticeClaimedAt(): ?\DateTimeImmutable
    {
        return $this->expiry_notice_claimed_at?->toDateTimeImmutable();
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revoked_at?->toDateTimeImmutable();
    }

    public function revokedByIdentifier(): string|int|null
    {
        return $this->revoked_by;
    }

    public function revokedReason(): ?RevocationReason
    {
        return $this->revoked_reason === null ? null : RevocationReason::tryFrom($this->revoked_reason);
    }

    public function revocationNote(): ?string
    {
        return $this->revocation_note;
    }

    public function exposedAt(): ?\DateTimeImmutable
    {
        return $this->exposed_at?->toDateTimeImmutable();
    }

    public function exposureSource(): ?string
    {
        return $this->exposure_source;
    }

    public function lastRotatedAt(): ?\DateTimeImmutable
    {
        return $this->last_rotated_at?->toDateTimeImmutable();
    }

    public function ownerIdentifier(): string|int|null
    {
        return $this->owner_id;
    }

    public function ownerPrincipalIdentifier(): ?string
    {
        return $this->owner_principal_id;
    }

    public function ownerKind(): ?string
    {
        $type = $this->ownerPrincipal?->type;

        return $type instanceof \BackedEnum ? (string) $type->value : $type;
    }

    public function createdByIdentifier(): string|int|null
    {
        return $this->created_by;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return $this->attributesToArray();
    }

    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->last_used_at?->toDateTimeImmutable();
    }

    public function lastUsedIp(): ?string
    {
        return $this->last_used_ip;
    }

    public function lastUsedUserAgent(): ?string
    {
        return $this->last_used_user_agent;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'restrictions' => 'array',
            'metadata' => 'array',
            'token_version' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'rate_limit_per_day' => 'integer',
            'rate_limit_per_month' => 'integer',
            'expires_at' => 'datetime',
            'expiry_notified_at' => 'datetime',
            'expiry_notice_claimed_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_rotated_at' => 'datetime',
            'exposed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'previous_key_valid_until' => 'datetime',
        ];
    }
}
