<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\WebhookData;
use App\Models\Webhook;
use App\Services\WebhookUrlGuardService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Registers an outbound webhook endpoint for the instance.
 *
 * Side effects: persists a {@see Webhook} row; returns the plain secret once for display.
 */
final class CreateWebhookAction
{
    public function __construct(
        private readonly WebhookUrlGuardService $urlGuard,
    ) {}

    public function execute(WebhookData $webhookData): Webhook
    {
        if ($refusal = $this->urlGuard->validate($webhookData->url)) {
            throw ValidationException::withMessages([
                'url' => $refusal,
            ]);
        }

        $plainSecret = $webhookData->secret ?? Str::random(40);

        $webhook = Webhook::query()->create([
            'url' => $webhookData->url,
            'secret' => $plainSecret,
            'events' => $webhookData->events,
            'is_active' => $webhookData->isActive,
        ]);

        // Surface once for the admin UI; the encrypted cast hides it on later reads.
        $webhook->setAttribute('plain_secret', $plainSecret);

        return $webhook;
    }
}
