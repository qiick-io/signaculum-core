<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookSubscription;

/**
 * Instance-admin webhook endpoint row for the settings list (qiick/laravel-outbound endpoint,
 * its subscriptions and its five most recent deliveries). Only public ids leave the database.
 *
 * @mixin WebhookEndpoint
 */
final class WebhookResource extends JsonResource
{
    /**
     * @return array{
     *     public_id: string,
     *     url: string,
     *     events: list<string>,
     *     is_active: bool,
     *     disabled_reason: string|null,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     recent_deliveries: list<array{event_id: string, event_type: string, status: string, http_status: int|null, attempt: int, created_at: string|null}>
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = $this->resource;

        $events = [];

        foreach ($endpoint->subscriptions as $subscription) {
            /** @var WebhookSubscription $subscription */
            $events[] = $subscription->event_type;
        }

        $recentDeliveries = [];

        if ($endpoint->relationLoaded('deliveries')) {
            foreach ($endpoint->deliveries->take(5) as $delivery) {
                /** @var WebhookDelivery $delivery */
                $recentDeliveries[] = [
                    'event_id' => $delivery->event_id,
                    'event_type' => $delivery->event_type,
                    'status' => $delivery->status->value,
                    'http_status' => $delivery->last_http_status,
                    'attempt' => $delivery->attempts_count,
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ];
            }
        }

        return [
            'public_id' => $endpoint->public_id,
            'url' => $endpoint->url,
            'events' => $events,
            'is_active' => $endpoint->is_active,
            'disabled_reason' => $endpoint->disabled_reason?->value,
            'created_at' => $endpoint->created_at?->toIso8601String(),
            'updated_at' => $endpoint->updated_at?->toIso8601String(),
            'recent_deliveries' => $recentDeliveries,
        ];
    }
}
