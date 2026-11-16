<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\WebhookData;
use App\Models\Webhook;
use App\Services\WebhookUrlGuardService;
use Illuminate\Validation\ValidationException;

/**
 * Updates URL, subscribed events and active flag for a webhook endpoint.
 */
final class UpdateWebhookAction
{
    public function __construct(
        private readonly WebhookUrlGuardService $urlGuard,
    ) {}

    public function execute(Webhook $webhook, WebhookData $webhookData): Webhook
    {
        if ($refusal = $this->urlGuard->validate($webhookData->url)) {
            throw ValidationException::withMessages([
                'url' => $refusal,
            ]);
        }

        $attributes = [
            'url' => $webhookData->url,
            'events' => $webhookData->events,
            'is_active' => $webhookData->isActive,
        ];

        if (is_string($webhookData->secret) && $webhookData->secret !== '') {
            $attributes['secret'] = $webhookData->secret;
        }

        $webhook->fill($attributes)->save();

        return $webhook;
    }
}
