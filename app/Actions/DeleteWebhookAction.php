<?php

declare(strict_types=1);

namespace App\Actions;

use Qiick\Outbound\Models\WebhookEndpoint;

/**
 * Removes a webhook endpoint. qiick/laravel-outbound soft-deletes it: it gets no new deliveries
 * and no new secret, and its delivery log stays until the retention prune.
 *
 * Side effects: writes a `deleted` entry in the default activity log.
 */
final class DeleteWebhookAction
{
    public function execute(WebhookEndpoint $endpoint): void
    {
        $endpoint->delete();

        activity('default')
            ->performedOn($endpoint)
            ->event('deleted')
            ->withProperties(['url' => $endpoint->url])
            ->log('Webhook deleted');
    }
}
