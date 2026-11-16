<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookUrlGuardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * POSTs a signed webhook payload to one registered endpoint.
 *
 * Side effects: HTTP POST; writes a {@see WebhookDelivery} row per attempt.
 */
final class DeliverWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 15;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $webhookId,
        public readonly string $eventId,
        public readonly string $eventType,
        public readonly string $createdAt,
        public readonly array $data,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(WebhookUrlGuardService $urlGuard): void
    {
        $webhook = Webhook::query()->find($this->webhookId);

        if ($webhook === null || ! $webhook->is_active) {
            return;
        }

        $attempt = $this->attempts();
        $authorized = $urlGuard->authorizeOutbound($webhook->url);

        if (! $authorized['ok']) {
            $this->recordDelivery($webhook, WebhookDelivery::STATUS_REFUSED, null, $authorized['reason'], $attempt);

            Log::warning('Webhook delivery refused: SSRF guard', [
                'webhook_id' => $webhook->uuid,
                'event_id' => $this->eventId,
                'event_type' => $this->eventType,
                'reason' => $authorized['reason'],
            ]);

            return;
        }

        $secret = $webhook->secret;

        if ($secret === '') {
            $this->recordDelivery($webhook, WebhookDelivery::STATUS_REFUSED, null, 'Signing secret empty.', $attempt);

            return;
        }

        $payload = json_encode([
            'id' => $this->eventId,
            'type' => $this->eventType,
            'created_at' => $this->createdAt,
            'data' => (object) $this->data,
        ], JSON_THROW_ON_ERROR);

        $timestamp = (string) now()->timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $pendingRequest = Http::timeout(5)
            ->connectTimeout(3)
            ->withoutRedirecting()
            ->withHeaders([
                'X-Signaculum-Signature' => $signature,
                'X-Signaculum-Timestamp' => $timestamp,
                'X-Signaculum-Event-Id' => $this->eventId,
                'X-Signaculum-Event' => $this->eventType,
                'User-Agent' => 'Signaculum-Webhooks/1.0',
            ])
            ->withBody($payload, 'application/json');

        if ($authorized['curl_resolve'] !== []) {
            $pendingRequest = $pendingRequest->withOptions([
                'curl' => [
                    CURLOPT_RESOLVE => $authorized['curl_resolve'],
                ],
            ]);
        }

        $response = $pendingRequest->post($webhook->url);

        if (! $response->successful()) {
            $this->recordDelivery(
                $webhook,
                WebhookDelivery::STATUS_FAILED,
                $response->status(),
                'HTTP '.$response->status(),
                $attempt,
            );

            throw new RuntimeException(sprintf(
                'Webhook delivery failed (%s): HTTP %s',
                $this->eventId,
                $response->status(),
            ));
        }

        $this->recordDelivery(
            $webhook,
            WebhookDelivery::STATUS_DELIVERED,
            $response->status(),
            null,
            $attempt,
            delivered: true,
        );
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Webhook delivery exhausted retries', [
            'webhook_id' => $this->webhookId,
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'message' => $exception?->getMessage(),
        ]);
    }

    private function recordDelivery(
        Webhook $webhook,
        string $status,
        ?int $httpStatus,
        ?string $errorMessage,
        int $attempt,
        bool $delivered = false,
    ): void {
        WebhookDelivery::query()->create([
            'webhook_id' => $webhook->getKey(),
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'attempt' => $attempt,
            'http_status' => $httpStatus,
            'status' => $status,
            'error_message' => $errorMessage,
            'delivered_at' => $delivered ? now() : null,
        ]);
    }
}
