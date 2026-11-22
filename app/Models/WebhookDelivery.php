<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One delivery attempt of a webhook event to a registered endpoint.
 *
 * @property int $id
 * @property int $webhook_id
 * @property string $event_id
 * @property string $event_type
 * @property int $attempt
 * @property int|null $http_status
 * @property string $status
 * @property string|null $error_message
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUSED = 'refused';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'webhook_id',
        'event_id',
        'event_type',
        'attempt',
        'http_status',
        'status',
        'error_message',
        'delivered_at',
    ];

    /**
     * @return BelongsTo<Webhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'http_status' => 'integer',
            'delivered_at' => 'datetime',
        ];
    }
}
