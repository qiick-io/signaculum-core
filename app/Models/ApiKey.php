<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\ApiKeyScopesData;
use App\Enums\ApiKeyScopeEnum;
use App\Services\ApiKeySecretService;
use App\Traits\LogsApplicationActivityTrait;
use App\Traits\RejectsInvalidUuidRouteKeysTrait;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Admin REST API key on the shared qiick baseline (app-ui docs/api-keys.md): looked up by its
 * public `key_prefix`, the full token stored only as a SHA-256 hash, and the secret replaced by the
 * last rotation kept in `previous_key_hash` until `previous_key_valid_until`. Token handling
 * lives in {@see ApiKeySecretService}; the plain-text token is never an attribute.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property int $owner_id
 * @property int|null $created_by
 * @property list<string>|null $scopes
 * @property list<string>|null $ip_allowlist
 * @property int|null $rate_limit_per_minute
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $revoked_at
 * @property string|null $previous_key_hash
 * @property Carbon|null $previous_key_valid_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiKey extends Model
{
    use HasUuids, LogsApplicationActivityTrait, RejectsInvalidUuidRouteKeysTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key_prefix',
        'key_hash',
        'owner_id',
        'created_by',
        'scopes',
        'ip_allowlist',
        'rate_limit_per_minute',
        'expires_at',
        'last_used_at',
        'last_used_ip',
        'revoked_at',
        'previous_key_hash',
        'previous_key_valid_until',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
        'previous_key_hash',
    ];

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
     * Who issued the key; kept apart from the owner the key acts as.
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
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * The stored scopes parsed against this product's vocabulary, or null when they do not
     * parse (missing, empty, malformed or no longer known): such a key may do nothing.
     */
    public function grantedScopes(): ?ApiKeyScopesData
    {
        return ApiKeyScopesData::fromStored($this->getAttribute('scopes'), ApiKeyScopeEnum::values());
    }

    /**
     * `write` implies `read` on the same resource (see {@see ApiKeyScopesData::allows()}).
     */
    public function hasScope(ApiKeyScopeEnum|string $scope): bool
    {
        $requiredScope = $scope instanceof ApiKeyScopeEnum ? $scope->value : $scope;

        return $this->grantedScopes()?->allows($requiredScope) ?? false;
    }

    /**
     * A null (or empty) allowlist allows every address. A stored allowlist that does not decode
     * to a list of strings allows none: an unreadable restriction is not "no restriction".
     */
    public function allowsIp(string $ipAddress): bool
    {
        if (($this->getAttributes()['ip_allowlist'] ?? null) === null) {
            return true;
        }

        $allowlist = $this->getAttribute('ip_allowlist');

        if (! is_array($allowlist) || ! array_is_list($allowlist)) {
            return false;
        }

        foreach ($allowlist as $allowedAddress) {
            if (! is_string($allowedAddress)) {
                return false;
            }
        }

        return $allowlist === [] || IpUtils::checkIp($ipAddress, $allowlist);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->logExcept(['key_hash', 'previous_key_hash']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'rate_limit_per_minute' => 'integer',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'previous_key_valid_until' => 'datetime',
        ];
    }
}
