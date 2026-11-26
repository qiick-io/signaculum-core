<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IssuedWebhookData;
use App\Data\WebhookData;
use App\Services\WebhookEndpointUrlService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Webhooks\SecretRotator;

/**
 * Registers an outbound webhook endpoint for the instance, on qiick/laravel-outbound's tables:
 * the endpoint, one subscription per event type, and a Standard Webhooks `whsec_` signing secret.
 *
 * Side effects: writes the endpoint, its subscriptions and its encrypted secret in one
 * transaction, and a `created` entry in the default activity log (URL and events, never the
 * secret). Returns the endpoint with its plain secret, shown once.
 *
 * @throws ValidationException when the URL is not a safe public destination
 */
final class CreateWebhookAction
{
    public function __construct(
        private readonly WebhookEndpointUrlService $webhookEndpointUrlService,
        private readonly SecretRotator $secretRotator,
    ) {}

    public function execute(WebhookData $webhookData): IssuedWebhookData
    {
        // The URL check and the flag read the stored setting under the lock the toggle writes it
        // with: a toggle cannot slip between, so both use the value that is persisted.
        return $this->webhookEndpointUrlService->whilePrivateHostsSettingIsLocked(function () use ($webhookData): IssuedWebhookData {
            $this->webhookEndpointUrlService->assertSafe($webhookData->url);

            return $this->persist($webhookData);
        });
    }

    private function persist(WebhookData $webhookData): IssuedWebhookData
    {
        return DB::transaction(function () use ($webhookData): IssuedWebhookData {

            $endpoint = new WebhookEndpoint(['url' => $webhookData->url]);
            // Not mass assignable in the package: only the product's own code sets them.
            $endpoint->forceFill([
                'is_active' => $webhookData->isActive,
                'allow_private_hosts' => $this->webhookEndpointUrlService->isPrivateHostsAllowed(),
                'created_by' => Auth::id(),
            ])->save();

            foreach ($webhookData->events as $eventType) {
                $endpoint->subscriptions()->create(['event_type' => $eventType]);
            }

            $secret = $this->secretRotator->issue($endpoint);

            activity('default')
                ->performedOn($endpoint)
                ->event('created')
                ->withProperties(['url' => $endpoint->url, 'events' => $webhookData->events, 'is_active' => $webhookData->isActive])
                ->log('Webhook created');

            // Shown once in the admin UI; the package stores it encrypted.
            return new IssuedWebhookData($endpoint, (string) $secret->secret);
        });
    }
}
