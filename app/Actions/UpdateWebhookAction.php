<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\WebhookData;
use App\Services\WebhookEndpointUrlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;
use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Updates an endpoint's URL, subscribed event types and active flag. Turning an endpoint on or
 * off goes through qiick/laravel-outbound's coordinator, the only supported path: enabling clears
 * a failure streak and resumes pending deliveries, disabling holds them.
 *
 * Side effects: replaces the subscriptions, fires the package's WebhookEndpointDisabledEvent on
 * a disable, and writes an `updated` entry in the activity log, all in one transaction.
 *
 * @throws ValidationException when the URL is not a safe public destination
 */
final class UpdateWebhookAction
{
    public function __construct(
        private readonly WebhookEndpointUrlService $webhookEndpointUrlService,
        private readonly WebhookDeliveryCoordinator $webhookDeliveryCoordinator,
    ) {}

    public function execute(WebhookEndpoint $endpoint, WebhookData $webhookData): WebhookEndpoint
    {
        $this->webhookEndpointUrlService->assertSafe($webhookData->url);

        // One transaction under the endpoint row lock the worker's claim also takes: a worker
        // sees the old destination or the new one with its new state, and a failure keeps both.
        DB::transaction(function () use ($endpoint, $webhookData): void {
            $lockedEndpoint = WebhookEndpoint::query()->whereKey($endpoint->getKey())->lockForUpdate()->firstOrFail();

            // Disabled before the destination changes, so a pending delivery never reaches it.
            if (! $webhookData->isActive && $lockedEndpoint->is_active) {
                $this->webhookDeliveryCoordinator->disableEndpoint($lockedEndpoint);
            }

            $lockedEndpoint->fill(['url' => $webhookData->url])->save();

            $lockedEndpoint->subscriptions()->whereNotIn('event_type', $webhookData->events)->delete();

            foreach ($webhookData->events as $eventType) {
                $lockedEndpoint->subscriptions()->firstOrCreate(['event_type' => $eventType]);
            }

            if ($webhookData->isActive && ! $lockedEndpoint->is_active) {
                $this->webhookDeliveryCoordinator->enableEndpoint($lockedEndpoint);
            }

            activity('default')
                ->performedOn($lockedEndpoint)
                ->event('updated')
                ->withProperties(['url' => $lockedEndpoint->url, 'events' => $webhookData->events, 'is_active' => $webhookData->isActive])
                ->log('Webhook updated');
        });

        return $endpoint->refresh();
    }
}
