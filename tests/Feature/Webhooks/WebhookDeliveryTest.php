<?php

declare(strict_types=1);

use App\Actions\CreateUserAction;
use App\Enums\WebhookEventEnum;
use App\Jobs\DeliverWebhookJob;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookDispatcherService;
use App\Services\WebhookUrlGuardService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('queues a signed delivery when a subscribed event fires', function (): void {
    Queue::fake();

    $admin = User::factory()->create(['is_instance_admin' => true]);

    $webhook = Webhook::query()->create([
        'url' => 'https://hooks.example.com/signaculum',
        'secret' => 'test-signing-secret-value',
        'events' => [WebhookEventEnum::UserCreated->value],
        'is_active' => true,
    ]);

    app(CreateUserAction::class)->execute('Ada', 'Lovelace', 'ada@example.com', 'Password1!');

    Queue::assertPushed(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($webhook): bool {
        return $job->webhookId === $webhook->getKey()
            && $job->eventType === WebhookEventEnum::UserCreated->value;
    });

    expect($admin->is_instance_admin)->toBeTrue();
});

it('posts an hmac signature and records a delivered attempt', function (): void {
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => true]);

    Http::fake([
        '127.0.0.1/*' => Http::response(['ok' => true], 200),
    ]);

    $webhook = Webhook::query()->create([
        'url' => 'http://127.0.0.1/signaculum',
        'secret' => 'test-signing-secret-value',
        'events' => [WebhookEventEnum::UserCreated->value],
        'is_active' => true,
    ]);

    $job = new DeliverWebhookJob(
        webhookId: $webhook->getKey(),
        eventId: '11111111-1111-4111-8111-111111111111',
        eventType: WebhookEventEnum::UserCreated->value,
        createdAt: now()->toIso8601String(),
        data: ['id' => 'subject', 'email' => 'ada@example.com', 'name' => 'Ada'],
    );

    $job->handle(app(WebhookUrlGuardService::class));

    Http::assertSent(function ($request) use ($webhook): bool {
        $timestamp = $request->header('X-Signaculum-Timestamp')[0] ?? '';
        $signature = $request->header('X-Signaculum-Signature')[0] ?? '';
        $body = $request->body();
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'test-signing-secret-value');

        return $request->url() === $webhook->url
            && $signature === $expected
            && $request->hasHeader('X-Signaculum-Event', WebhookEventEnum::UserCreated->value);
    });

    expect(WebhookDelivery::query()->where('status', WebhookDelivery::STATUS_DELIVERED)->exists())->toBeTrue();
});

it('refuses private webhook urls unless the instance setting allows them', function (): void {
    $settings = app(SettingsRepositoryService::class);
    $guard = new WebhookUrlGuardService($settings, static fn (string $_host): array => ['93.184.216.34']);

    expect($guard->validate('https://127.0.0.1/hook'))->not->toBeNull()
        ->and($guard->validate('https://192.168.1.10/hook'))->not->toBeNull()
        ->and($guard->validate('https://hooks.example.com/ok'))->toBeNull();

    $settings->save(['webhooks_allow_private_urls' => true]);

    expect($guard->validate('https://127.0.0.1/hook'))->toBeNull()
        ->and($guard->validate('https://169.254.169.254/latest'))->not->toBeNull();
});

it('lets an instance admin create a webhook endpoint', function (): void {
    $admin = User::factory()->create(['is_instance_admin' => true]);

    app()->bind(WebhookUrlGuardService::class, fn (): WebhookUrlGuardService => new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static fn (string $_host): array => ['93.184.216.34'],
    ));

    $this->actingAs($admin)
        ->post(route('instance.webhooks.store'), [
            'url' => 'https://hooks.example.com/signaculum',
            'events' => [WebhookEventEnum::UserSignedIn->value],
            'is_active' => true,
        ])
        ->assertRedirect(route('instance.webhooks.index'));

    expect(Webhook::query()->where('url', 'https://hooks.example.com/signaculum')->exists())->toBeTrue();
});

it('does not dispatch when no webhook is subscribed to the event', function (): void {
    Queue::fake();

    Webhook::query()->create([
        'url' => 'https://hooks.example.com/signaculum',
        'secret' => 'test-signing-secret-value',
        'events' => [WebhookEventEnum::ApplicationCreated->value],
        'is_active' => true,
    ]);

    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, [
        'id' => 'x',
        'email' => 'a@example.com',
        'name' => 'A',
    ]);

    Queue::assertNothingPushed();
});
