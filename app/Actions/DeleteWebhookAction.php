<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Webhook;

/**
 * Removes a webhook endpoint and its delivery log (cascade).
 */
final class DeleteWebhookAction
{
    public function execute(Webhook $webhook): void
    {
        $webhook->delete();
    }
}
