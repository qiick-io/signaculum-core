<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\LogsApplicationActivityTrait;
use App\Traits\RejectsInvalidUuidRouteKeysTrait;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Outbound webhook endpoint registered by an instance admin.
 *
 * @property int $id
 * @property string $uuid
 * @property string $url
 * @property string $secret plain signing secret (encrypted at rest)
 * @property list<string> $events
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Webhook extends Model
{
    use HasUuids, LogsApplicationActivityTrait, RejectsInvalidUuidRouteKeysTrait;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'url',
        'secret',
        'events',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret',
    ];

    /**
     * Public id for routes and payloads (not the auto-increment primary key).
     *
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
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->applicationActivityLogOptions()
            ->logExcept(['secret']);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
