<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApiKeyScopeEnum;
use App\Traits\LogsApplicationActivityTrait;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Activitylog\Support\LogOptions;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Admin REST API key: Bearer secret shown once, stored as SHA-256 hash + display prefix.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $key_prefix
 * @property string $key_hash
 * @property int $owner_id
 * @property list<string> $scopes
 * @property list<string>|null $ip_allowlist
 * @property int|null $rate_limit_per_minute
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ApiKey extends Model
{
    use HasUuids, LogsApplicationActivityTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'key_prefix',
        'key_hash',
        'owner_id',
        'scopes',
        'ip_allowlist',
        'rate_limit_per_minute',
        'expires_at',
        'last_used_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'key_hash',
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

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function hasScope(ApiKeyScopeEnum|string $scope): bool
    {
        $value = $scope instanceof ApiKeyScopeEnum ? $scope->value : $scope;

        return in_array($value, $this->scopes, true);
    }

    public function allowsIp(string $ipAddress): bool
    {
        $allowlist = $this->ip_allowlist;

        if ($allowlist === null || $allowlist === []) {
            return true;
        }

        return IpUtils::checkIp($ipAddress, $allowlist);
    }

    /**
     * @return array{plain: string, prefix: string, hash: string}
     */
    public static function generateSecret(): array
    {
        $prefix = (string) config('api.key_prefix', 'sk_');
        $plain = $prefix.Str::random(40);

        return [
            'plain' => $plain,
            'prefix' => substr($plain, 0, 12),
            'hash' => self::hashSecret($plain),
        ];
    }

    public static function hashSecret(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->logExcept(['key_hash']);
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
        ];
    }
}
