<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Qiick\Outbound\Delivery\WebhookDeliveryCoordinator;

/**
 * Sends the due webhook deliveries right after an event stored them, so a first attempt does
 * not wait for the scheduler (qiick/laravel-outbound's `work()`). Each delivery is leased just
 * before its send, so this job and the scheduled retry run never send one twice. Queued after
 * commit: a rolled-back event sends nothing.
 *
 * Side effects: outbound HTTP POSTs to the subscribed endpoints, attempt rows and endpoint
 * failure streaks, through the package.
 */
final class SendWebhookDeliveriesJob implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Seconds after which the job starts no new send. A send may take up to
     * `outbound.http.total_deadline_seconds` (30), so the job ends within about 50 seconds,
     * inside a queue worker's default 60-second timeout; what is left stays due for the next job
     * or the scheduled run.
     */
    public const int SEND_WINDOW_SECONDS = 20;

    public function handle(WebhookDeliveryCoordinator $webhookDeliveryCoordinator): void
    {
        $sendWindowEndsAt = now()->addSeconds(self::SEND_WINDOW_SECONDS);

        // One delivery per call, so the window is checked before every send.
        while (now()->lessThan($sendWindowEndsAt) && $webhookDeliveryCoordinator->work(1) > 0) {
            continue;
        }
    }
}
