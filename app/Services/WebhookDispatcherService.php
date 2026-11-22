<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WebhookEventEnum;
use App\Jobs\DeliverWebhookJob;
use App\Models\Webhook;
use Illuminate\Support\Str;

/**
 * Fans a domain event out to every active webhook that subscribed to its type.
 *
 * Side effects: queues one {@see DeliverWebhookJob} per matching endpoint.
 */
final class WebhookDispatcherService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(WebhookEventEnum $event, array $data): void
    {
        $eventId = (string) Str::uuid();
        $createdAt = now()->toIso8601String();

        $webhooks = Webhook::query()
            ->where('is_active', true)
            ->get();

        foreach ($webhooks as $webhook) {
            if (! in_array($event->value, $webhook->events, true)) {
                continue;
            }

            DeliverWebhookJob::dispatch(
                webhookId: $webhook->getKey(),
                eventId: $eventId,
                eventType: $event->value,
                createdAt: $createdAt,
                data: $data,
            );
        }
    }
}
