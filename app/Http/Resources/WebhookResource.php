<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Instance-admin webhook endpoint row for the settings list.
 *
 * @mixin Webhook
 */
final class WebhookResource extends JsonResource
{
    /**
     * @return array{
     *     uuid: string,
     *     url: string,
     *     events: list<string>,
     *     is_active: bool,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     recent_deliveries: list<array{event_id: string, event_type: string, status: string, http_status: int|null, attempt: int, created_at: string|null}>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Webhook $webhook */
        $webhook = $this->resource;

        /** @var list<string> $events */
        $events = $webhook->events;

        $recentDeliveries = [];

        if ($webhook->relationLoaded('deliveries')) {
            foreach ($webhook->deliveries->take(5) as $delivery) {
                /** @var WebhookDelivery $delivery */
                $recentDeliveries[] = [
                    'event_id' => $delivery->event_id,
                    'event_type' => $delivery->event_type,
                    'status' => $delivery->status,
                    'http_status' => $delivery->http_status,
                    'attempt' => $delivery->attempt,
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ];
            }
        }

        return [
            'uuid' => $webhook->uuid,
            'url' => $webhook->url,
            'events' => $events,
            'is_active' => $webhook->is_active,
            'created_at' => $webhook->created_at?->toIso8601String(),
            'updated_at' => $webhook->updated_at?->toIso8601String(),
            'recent_deliveries' => $recentDeliveries,
        ];
    }
}
