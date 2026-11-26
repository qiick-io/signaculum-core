<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WebhookEventEnum;
use App\Jobs\SendWebhookDeliveriesJob;
use Illuminate\Support\Str;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Webhooks\WebhookMessage;

/**
 * Fans a domain event out to every active webhook endpoint subscribed to its type, through
 * qiick/laravel-outbound: Standard Webhooks envelope `{type, timestamp, data}` and headers
 * (`webhook-id`, `webhook-timestamp`, `webhook-signature`), signed with the endpoint's `whsec_`
 * secrets.
 *
 * Side effects: stores one pending delivery per subscribed endpoint, with the exact body every
 * attempt signs, and queues one {@see SendWebhookDeliveriesJob} (after commit) for the first
 * attempt; `outbound:webhooks:work`, scheduled every minute, sends the retries.
 */
final class WebhookDispatcherService
{
    public function __construct(
        private readonly WebhookDeliveryCoordinator $webhookDeliveryCoordinator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(WebhookEventEnum $event, array $data): void
    {
        $deliveries = $this->webhookDeliveryCoordinator->dispatch(new WebhookMessage(
            'msg_'.Str::ulid(),
            $event->value,
            now()->toDateTimeImmutable(),
            $data,
        ));

        if ($deliveries->isNotEmpty()) {
            SendWebhookDeliveriesJob::dispatch();
        }
    }
}
