<?php

declare(strict_types=1);

use App\Enums\WebhookEventEnum;
use App\Jobs\SendWebhookDeliveriesJob;
use App\Services\WebhookDispatcherService;
use App\Services\WebhookEndpointUrlService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Qiick\Outbound\Delivery\DeliveryStatus;
use Qiick\Outbound\Models\WebhookDelivery;
use Qiick\Outbound\Models\WebhookEndpointSecret;
use Qiick\Outbound\Webhooks\StandardWebhookVerifier;
use Qiick\Outbound\Webhooks\WebhookSecret;

/**
 * Signaculum's events go out through qiick/laravel-outbound (C27): stored per subscribed endpoint,
 * sent by `outbound:webhooks:work` as Standard Webhooks (`webhook-id`, `webhook-timestamp`,
 * `webhook-signature` with the endpoint's `whsec_` secret, body `{type, timestamp, data}`), and
 * only to a public address unless the instance allows private webhook URLs.
 */
test('an event is stored once per active endpoint subscribed to its type', function (): void {
    $subscribed = existingWebhook(['events' => [WebhookEventEnum::UserCreated->value]]);
    existingWebhook(['url' => 'https://hooks.example.com/other', 'events' => [WebhookEventEnum::UserLocked->value]]);
    existingWebhook(['url' => 'https://hooks.example.com/off', 'is_active' => false]);

    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);

    $deliveries = WebhookDelivery::query()->get();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries->first()?->endpoint_id)->toBe($subscribed->id)
        ->and($deliveries->first()?->status)->toBe(DeliveryStatus::Pending)
        ->and($deliveries->first()?->event_type)->toBe('user.created');
});

test('the worker sends a Standard Webhooks request that verifies with the endpoint secret', function (): void {
    $http = fakeWebhookNetwork();
    $endpoint = existingWebhook();
    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);

    $this->artisan('outbound:webhooks:work')->assertSuccessful();

    /** @var Request $request */
    $request = $http->recorded()[0][0];
    $secret = WebhookEndpointSecret::query()->where('endpoint_id', $endpoint->id)->sole()->secret;
    $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

    expect($secret)->toStartWith('whsec_')
        ->and(array_keys($body))->toBe(['type', 'timestamp', 'data'])
        ->and($body['type'])->toBe('user.created')
        ->and($body['data'])->toBe(['user_id' => 'subject-1'])
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Delivered);

    app(StandardWebhookVerifier::class)->verify(
        $request->body(),
        $request->header('webhook-id')[0],
        $request->header('webhook-timestamp')[0],
        $request->header('webhook-signature')[0],
        [WebhookSecret::parse((string) $secret)],
        new DateTimeImmutable,
        dedupeScope: 'signaculum-test',
    );
});

test('a private destination is refused unless the instance allows private webhook URLs', function (): void {
    $http = fakeWebhookNetwork(['hooks.internal.test' => ['10.0.0.5']]);
    existingWebhook(['url' => 'https://hooks.internal.test/hook']);
    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);

    $this->artisan('outbound:webhooks:work')->assertSuccessful();

    expect($http->recorded())->toBeEmpty()
        ->and(WebhookDelivery::query()->sole()->status)->not->toBe(DeliveryStatus::Delivered);

    app(WebhookEndpointUrlService::class)->savePrivateHostsSetting(true);
    WebhookDelivery::query()->update(['next_attempt_at' => now()->subSecond()]);

    $this->artisan('outbound:webhooks:work')->assertSuccessful();

    expect($http->recorded())->toHaveCount(1);
});

// The first attempt does not wait for the scheduler: the dispatch queues one job (after commit),
// and only when an endpoint is subscribed, so an event nobody listens to costs nothing.
test('an event with subscribers queues one send job, an event without none', function (): void {
    Queue::fake();
    existingWebhook(['events' => [WebhookEventEnum::UserCreated->value]]);

    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserLocked, ['user_id' => 'subject-1']);
    Queue::assertNotPushed(SendWebhookDeliveriesJob::class);

    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);
    Queue::assertPushed(SendWebhookDeliveriesJob::class, 1);
});

test('the send job delivers the first attempt at once', function (): void {
    $http = fakeWebhookNetwork();
    existingWebhook();
    Queue::fake();
    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);

    app()->call([new SendWebhookDeliveriesJob, 'handle']);

    expect($http->recorded())->toHaveCount(1)
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Delivered);
});

test('turning private webhook URLs off stops deliveries to a private endpoint already registered', function (): void {
    $http = fakeWebhookNetwork(['hooks.internal.test' => ['10.0.0.5']]);
    Queue::fake();
    $admin = instanceAdmin();
    existingWebhook(['url' => 'https://hooks.internal.test/hook']);
    $this->actingAs($admin)->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => '1'])->assertRedirect();
    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);

    $this->actingAs($admin)->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => '0'])->assertRedirect();
    $this->artisan('outbound:webhooks:work')->assertSuccessful();

    expect($http->recorded())->toBeEmpty();
});

test('an edit that moves and disables an endpoint holds its pending delivery', function (): void {
    $http = fakeWebhookNetwork(['hooks.example.com' => ['93.184.216.34'], 'receiver.example.com' => ['93.184.216.35']]);
    Queue::fake();
    $webhook = existingWebhook();
    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);

    $this->actingAs(instanceAdmin())->put(route('instance.webhooks.update', $webhook), [
        'url' => 'https://receiver.example.com/hook',
        'events' => [WebhookEventEnum::UserCreated->value],
        'is_active' => false,
    ])->assertRedirect();
    $this->artisan('outbound:webhooks:work')->assertSuccessful();

    expect($http->recorded())->toBeEmpty()
        ->and(WebhookDelivery::query()->sole()->status)->toBe(DeliveryStatus::Pending);
});

// A queue worker stops a job after 60 seconds by default: the job starts no send once
// SendWebhookDeliveriesJob::SEND_WINDOW_SECONDS have passed, so its last send still fits.
test('the send job starts no new send once its time window has passed', function (): void {
    $http = fakeWebhookNetwork(response: ['*' => function () {
        $this->travel(15)->seconds();

        return HttpFactory::response('', 204);
    }]);
    Queue::fake();

    foreach (range(1, 4) as $endpointNumber) {
        existingWebhook(['url' => "https://hooks.example.com/{$endpointNumber}"]);
    }

    app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, ['user_id' => 'subject-1']);
    app()->call([new SendWebhookDeliveriesJob, 'handle']);

    expect($http->recorded())->toHaveCount(2);
});

// The package's private-host ceiling is open (config/outbound.php), so a missing endpoint flag
// would inherit it at delivery: the database refuses one, whoever writes the row (a restore, an
// import, a query without model events).
test('the database refuses an endpoint without a private-hosts flag', function (): void {
    $endpoint = existingWebhook();

    expect(fn () => DB::table('webhook_endpoints')->where('id', $endpoint->getKey())->update(['allow_private_hosts' => null]))
        ->toThrow(QueryException::class);
});

test('the endpoint private-hosts flag is required and defaults to refusing', function (): void {
    $column = collect(Schema::getColumns('webhook_endpoints'))->firstWhere('name', 'allow_private_hosts');

    expect($column['nullable'])->toBeFalse()
        ->and((string) $column['default'])->toBeIn(['0', 'false', "'0'", '(0)']);
});
