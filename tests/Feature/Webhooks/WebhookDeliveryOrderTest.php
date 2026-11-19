<?php

declare(strict_types=1);

use App\Enums\WebhookEventEnum;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Recent deliveries must stay newest-first when several rows share `created_at`
 * (MariaDB otherwise returns a non-deterministic order for `latest()` alone).
 */
it('lists recent deliveries by created_at then id when timestamps are equal', function (): void {
    Carbon::setTestNow('2026-11-19 21:00:00');

    $webhook = existingWebhook();
    $eventIds = [];

    foreach (range(1, 3) as $index) {
        $eventId = (string) Str::uuid();
        $eventIds[$index] = $eventId;

        WebhookDelivery::query()->create([
            'webhook_id' => $webhook->getKey(),
            'event_id' => $eventId,
            'event_type' => WebhookEventEnum::UserCreated->value,
            'attempt' => 1,
            'http_status' => 200,
            'status' => WebhookDelivery::STATUS_DELIVERED,
            'delivered_at' => now(),
        ]);
    }

    expect(
        WebhookDelivery::query()
            ->where('webhook_id', $webhook->getKey())
            ->pluck('created_at')
            ->unique()
            ->count(),
    )->toBe(1);

    $this->actingAs(instanceAdmin())
        ->get(route('instance.webhooks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/webhooks')
            ->has('webhooks.data.0.recent_deliveries', 3)
            ->where('webhooks.data.0.recent_deliveries.0.event_id', $eventIds[3])
            ->where('webhooks.data.0.recent_deliveries.1.event_id', $eventIds[2])
            ->where('webhooks.data.0.recent_deliveries.2.event_id', $eventIds[1]));
});
