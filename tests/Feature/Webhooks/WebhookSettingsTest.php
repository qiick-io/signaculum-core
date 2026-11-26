<?php

declare(strict_types=1);

use App\Actions\CreateWebhookAction;
use App\Data\WebhookData;
use App\Enums\WebhookEventEnum;
use App\Models\Setting;
use App\Models\User;
use App\Policies\SettingPolicy;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookEndpointUrlService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Qiick\Outbound\Events\WebhookEndpointDisabledEvent;
use Qiick\Outbound\Models\WebhookEndpoint;
use Qiick\Outbound\Models\WebhookEndpointSecret;

/**
 * Settings → Webhooks (#180): who may see and change an outbound endpoint, what the update
 * endpoint validates, and what the list does and does not put on the page.
 *
 * An endpoint is an instance-wide egress destination, so every route here is instance-admin
 * only ({@see SettingPolicy}) and the URL goes through the SSRF guard on every
 * write, not only on create.
 */
function webhookSettingsGuardResolvingTo(array $addresses = ['93.184.216.34']): void
{
    $hosts = [];

    foreach (['hooks.example.com', 'inside.example.com', 'rebound.example.com', 'metadata.google.internal', 'metadata'] as $host) {
        $hosts[$host] = $addresses;
    }

    fakeWebhookNetwork($hosts);
}

/**
 * The event types an endpoint is subscribed to (qiick/laravel-outbound subscriptions).
 *
 * @return list<string>
 */
function webhookEvents(?WebhookEndpoint $endpoint): array
{
    return $endpoint?->subscriptions()->orderBy('id')->pluck('event_type')->all() ?? [];
}

function webhookPayload(array $overrides = []): array
{
    return [
        'url' => 'https://hooks.example.com/updated',
        'events' => [WebhookEventEnum::UserUpdated->value],
        'is_active' => true,
        ...$overrides,
    ];
}

it('sends a guest to login on every webhook settings route', function (): void {
    $webhook = existingWebhook();

    $this->get(route('instance.webhooks.index'))->assertRedirect(route('login'));
    $this->post(route('instance.webhooks.store'), webhookPayload())->assertRedirect(route('login'));
    $this->put(route('instance.webhooks.update', $webhook), webhookPayload())->assertRedirect(route('login'));
    $this->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => true])->assertRedirect(route('login'));
    $this->delete(route('instance.webhooks.destroy', $webhook))->assertRedirect(route('login'));

    expect($webhook->fresh()?->url)->toBe('https://hooks.example.com/existing')
        ->and(Setting::query()->count())->toBe(0);
});

it('refuses every webhook settings route to a user who is not an instance admin', function (): void {
    $user = User::factory()->create();
    $webhook = existingWebhook();

    $this->actingAs($user)->get(route('instance.webhooks.index'))->assertForbidden();
    $this->actingAs($user)->post(route('instance.webhooks.store'), webhookPayload())->assertForbidden();
    $this->actingAs($user)->put(route('instance.webhooks.update', $webhook), webhookPayload())->assertForbidden();
    $this->actingAs($user)->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => true])->assertForbidden();
    $this->actingAs($user)->delete(route('instance.webhooks.destroy', $webhook))->assertForbidden();

    $unchanged = $webhook->fresh();

    expect($unchanged)->not->toBeNull()
        ->and($unchanged?->url)->toBe('https://hooks.example.com/existing')
        ->and(webhookEvents($unchanged))->toBe([WebhookEventEnum::UserCreated->value])
        ->and(Setting::query()->count())->toBe(0);
});

it('does not let an organisation owner reach the instance webhook endpoints', function (): void {
    $owner = User::factory()->create();
    createOrganizationWithOwner($owner, ['slug' => 'webhook-outsider']);
    $webhook = existingWebhook();

    $this->actingAs($owner)->get(route('instance.webhooks.index'))->assertForbidden();
    $this->actingAs($owner)->delete(route('instance.webhooks.destroy', $webhook))->assertForbidden();

    expect($webhook->fresh())->not->toBeNull();
});

it('lists the endpoints for an instance admin with their recent deliveries', function (): void {
    $webhook = existingWebhook();

    $eventIds = [];

    foreach (range(1, 6) as $index) {
        $eventId = (string) Str::uuid();
        $eventIds[$index] = $eventId;

        // A delivered row as qiick/laravel-outbound stores it; created one second apart.
        DB::table('webhook_deliveries')->insert([
            'public_id' => (string) Str::uuid7(),
            'endpoint_id' => $webhook->id,
            'event_id' => $eventId,
            'event_type' => WebhookEventEnum::UserCreated->value,
            'occurred_at' => now(),
            'payload_version' => '1',
            'status' => 'delivered',
            'attempts_count' => 1,
            'max_attempts' => 10,
            'last_http_status' => 200,
            'delivered_at' => now(),
            'payload_hash' => hash('sha256', $eventId),
            'payload_bytes' => 2,
            'payload_expires_at' => now()->addDays(30),
            'trigger' => 'event',
            'created_at' => now()->addSeconds($index),
            'updated_at' => now()->addSeconds($index),
        ]);
    }

    $this->actingAs(instanceAdmin())->get(route('instance.webhooks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/webhooks')
            ->has('webhooks.data', 1)
            ->where('webhooks.data.0.public_id', $webhook->public_id)
            ->where('webhooks.data.0.url', 'https://hooks.example.com/existing')
            ->where('webhooks.data.0.events', [WebhookEventEnum::UserCreated->value])
            ->where('webhooks.data.0.is_active', true)
            // The resource shows at most five attempts, newest first.
            ->has('webhooks.data.0.recent_deliveries', 5)
            ->where('webhooks.data.0.recent_deliveries.0.event_id', $eventIds[6])
            ->where('webhooks.data.0.recent_deliveries.0.status', 'delivered')
            ->where('webhooks.data.0.recent_deliveries.0.http_status', 200)
            ->where('webhooks.data.0.recent_deliveries.0.attempt', 1)
            ->has('availableEvents')
            ->has('settings'));
});

it('answers 404 for a webhook route key that is not a uuid', function (): void {
    existingWebhook();

    $this->actingAs(instanceAdmin())
        ->put(route('instance.webhooks.update', ['webhook' => 'not-a-uuid']), webhookPayload())
        ->assertNotFound();

    $this->actingAs(instanceAdmin())
        ->delete(route('instance.webhooks.destroy', ['webhook' => 'not-a-uuid']))
        ->assertNotFound();
});

it('never puts a signing secret on the webhooks page', function (): void {
    existingWebhook();

    $response = $this->actingAs(instanceAdmin())->get(route('instance.webhooks.index'))->assertOk();

    $secret = (string) WebhookEndpointSecret::query()->sole()->secret;

    expect($secret)->toStartWith('whsec_')
        ->and($response->getContent())->not->toContain($secret);
});

it('shows the plain secret once after a create and never again', function (): void {
    webhookSettingsGuardResolvingTo();
    $admin = instanceAdmin();

    $this->actingAs($admin)
        ->post(route('instance.webhooks.store'), webhookPayload(['url' => 'https://hooks.example.com/fresh']))
        ->assertRedirect(route('instance.webhooks.index'));

    $firstLoad = $this->actingAs($admin)->get(route('instance.webhooks.index'))->assertOk();
    $secondLoad = $this->actingAs($admin)->get(route('instance.webhooks.index'))->assertOk();

    $shownSecret = $firstLoad->viewData('page')['props']['plainTextSecret'] ?? null;

    expect($shownSecret)->toBeString()->toStartWith('whsec_')
        ->and($secondLoad->viewData('page')['props']['plainTextSecret'] ?? null)->toBeNull();
});

it('refuses a malformed webhook update field by field, leaving the endpoint as it was', function (array $payload, array $invalidFields): void {
    webhookSettingsGuardResolvingTo();
    $webhook = existingWebhook();

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->put(route('instance.webhooks.update', $webhook), $payload)
        ->assertSessionHasErrors($invalidFields);

    $unchanged = $webhook->fresh();

    expect($unchanged?->url)->toBe('https://hooks.example.com/existing')
        ->and(webhookEvents($unchanged))->toBe([WebhookEventEnum::UserCreated->value])
        ->and($unchanged?->is_active)->toBeTrue();
})->with([
    'nothing at all' => [[], ['url', 'events', 'is_active']],
    'no url' => [['events' => [WebhookEventEnum::UserUpdated->value], 'is_active' => true], ['url']],
    'url is not a url' => [['url' => 'not-a-url', 'events' => [WebhookEventEnum::UserUpdated->value], 'is_active' => true], ['url']],
    'url is ftp' => [['url' => 'ftp://hooks.example.com/hook', 'events' => [WebhookEventEnum::UserUpdated->value], 'is_active' => true], ['url']],
    'url too long' => [['url' => 'https://hooks.example.com/'.str_repeat('a', 2100), 'events' => [WebhookEventEnum::UserUpdated->value], 'is_active' => true], ['url']],
    'no events' => [['url' => 'https://hooks.example.com/updated', 'events' => [], 'is_active' => true], ['events']],
    'unknown event' => [['url' => 'https://hooks.example.com/updated', 'events' => ['user.exploded'], 'is_active' => true], ['events.0']],
    'is_active missing' => [['url' => 'https://hooks.example.com/updated', 'events' => [WebhookEventEnum::UserUpdated->value]], ['is_active']],
    'is_active not boolean' => [['url' => 'https://hooks.example.com/updated', 'events' => [WebhookEventEnum::UserUpdated->value], 'is_active' => 'perhaps'], ['is_active']],
]);

it('runs the ssrf guard on create, so a private endpoint is never registered', function (): void {
    // Every host resolves to a private address, so no endpoint may be created.
    webhookSettingsGuardResolvingTo(['192.168.1.10']);

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->post(route('instance.webhooks.store'), webhookPayload(['url' => 'https://inside.example.com/hook']))
        ->assertSessionHasErrors('url');

    expect(WebhookEndpoint::query()->count())->toBe(0);
});

it('runs the ssrf guard on an update, not only on create', function (): void {
    // The guard resolves every host to loopback, so no hostname may be saved.
    webhookSettingsGuardResolvingTo(['127.0.0.1']);
    $webhook = existingWebhook();

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->put(route('instance.webhooks.update', $webhook), webhookPayload(['url' => 'https://rebound.example.com/hook']))
        ->assertSessionHasErrors('url');

    expect($webhook->fresh()?->url)->toBe('https://hooks.example.com/existing');
});

it('refuses to point an endpoint at a cloud metadata service', function (string $url): void {
    webhookSettingsGuardResolvingTo();
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => true]);
    $webhook = existingWebhook();

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->put(route('instance.webhooks.update', $webhook), webhookPayload(['url' => $url]))
        ->assertSessionHasErrors('url');

    expect($webhook->fresh()?->url)->toBe('https://hooks.example.com/existing');
})->with([
    'ec2 imds' => 'http://169.254.169.254/latest/meta-data/',
    'gce metadata' => 'http://metadata.google.internal/computeMetadata/v1/',
    'bare metadata host' => 'http://metadata/computeMetadata/',
]);

it('updates the url events and active flag of an endpoint', function (): void {
    webhookSettingsGuardResolvingTo();
    $webhook = existingWebhook();
    $secretBefore = WebhookEndpointSecret::query()->sole()->secret;

    $this->actingAs(instanceAdmin())
        ->put(route('instance.webhooks.update', $webhook), webhookPayload([
            'url' => 'https://hooks.example.com/moved',
            'events' => [
                WebhookEventEnum::UserDeleted->value,
                WebhookEventEnum::UserDeleted->value,
                WebhookEventEnum::ApplicationCreated->value,
            ],
            'is_active' => false,
        ]))
        ->assertRedirect(route('instance.webhooks.index'));

    $updated = $webhook->fresh();

    expect($updated?->url)->toBe('https://hooks.example.com/moved')
        ->and(webhookEvents($updated))->toEqualCanonicalizing([
            WebhookEventEnum::UserDeleted->value,
            WebhookEventEnum::ApplicationCreated->value,
        ])
        ->and($updated?->is_active)->toBeFalse()
        // An update never touches the signing secret.
        ->and(WebhookEndpointSecret::query()->sole()->secret)->toBe($secretBefore);
});

it('deletes an endpoint and its delivery history stops being listed', function (): void {
    $webhook = existingWebhook();

    $this->actingAs(instanceAdmin())
        ->delete(route('instance.webhooks.destroy', $webhook))
        ->assertRedirect(route('instance.webhooks.index'));

    // qiick/laravel-outbound soft-deletes it: gone from the page, the delivery log kept until pruned.
    expect(WebhookEndpoint::query()->whereKey($webhook->getKey())->exists())->toBeFalse();
});

it('answers 404 for a webhook uuid that does not exist', function (): void {
    $admin = instanceAdmin();

    $this->actingAs($admin)->put(route('instance.webhooks.update', 'c0ffee00-0000-4000-8000-000000000000'), webhookPayload())->assertNotFound();
    $this->actingAs($admin)->delete(route('instance.webhooks.destroy', 'c0ffee00-0000-4000-8000-000000000000'))->assertNotFound();
});

it('refuses a malformed private-urls toggle and saves nothing', function (mixed $value): void {
    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => $value])
        ->assertSessionHasErrors('webhooks_allow_private_urls');

    expect(app(SettingsRepositoryService::class)->areWebhookPrivateUrlsAllowed())->toBeFalse();
})->with([
    'missing' => null,
    'not a boolean' => 'sometimes',
    'an array' => [[['1']]],
]);

it('saves the private-urls toggle and the guard then honours it, on every endpoint', function (): void {
    fakeWebhookNetwork(['loopback.example.com' => ['127.0.0.1']]);
    $webhook = existingWebhook();
    $settings = app(SettingsRepositoryService::class);

    expect($settings->areWebhookPrivateUrlsAllowed())->toBeFalse();

    $this->actingAs(instanceAdmin())
        ->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => '1'])
        ->assertRedirect(route('instance.webhooks.index'));

    expect(app(SettingsRepositoryService::class)->areWebhookPrivateUrlsAllowed())->toBeTrue()
        ->and(app(WebhookEndpointUrlService::class)->authorization('https://loopback.example.com/hook')[0])->toBeTrue()
        ->and($webhook->fresh()?->allow_private_hosts)->toBeTrue();

    $this->actingAs(instanceAdmin())
        ->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => '0'])
        ->assertRedirect(route('instance.webhooks.index'));

    expect(app(SettingsRepositoryService::class)->areWebhookPrivateUrlsAllowed())->toBeFalse()
        ->and(app(WebhookEndpointUrlService::class)->authorization('https://loopback.example.com/hook')[0])->toBeFalse()
        ->and($webhook->fresh()?->allow_private_hosts)->toBeFalse();
});

// The toggle and the endpoint flags the worker reads change together, or not at all.
it('saves the private-urls toggle and every endpoint flag in one transaction', function (): void {
    fakeWebhookNetwork();
    existingWebhook();
    $admin = instanceAdmin();
    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with(strtolower(ltrim($query)), 'update') && str_contains($query, 'webhook_endpoints')) {
            throw new RuntimeException('The endpoint write failed.');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => '1']))
        ->toThrow(RuntimeException::class, 'The endpoint write failed.');

    expect(app(SettingsRepositoryService::class)->areWebhookPrivateUrlsAllowed())->toBeFalse();
});

// A create reads the toggle under the same lock the toggle writes it with, so an endpoint never
// keeps a flag the instance setting has already turned off.
it('registers an endpoint only while no private-urls change is in progress', function (): void {
    webhookSettingsGuardResolvingTo();
    $toggleLock = Cache::lock(WebhookEndpointUrlService::PRIVATE_HOSTS_LOCK, 30);
    $toggleLock->get();

    try {
        expect(fn () => app(CreateWebhookAction::class)->execute(new WebhookData('https://hooks.example.com/new', [WebhookEventEnum::UserCreated->value], true)))
            ->toThrow(LockTimeoutException::class);
    } finally {
        $toggleLock->release();
    }

    expect(WebhookEndpoint::query()->exists())->toBeFalse();
});

// Signaculum's toggle is the only switch for private destinations: the package's ceiling stays
// open whatever the environment says, so an override can neither bypass the toggle (each
// endpoint narrows it) nor refuse endpoints the toggle accepted.
it('keeps the package ceiling for private destinations out of the environment', function (): void {
    putenv('OUTBOUND_ALLOW_PRIVATE_HOSTS=false');
    $_ENV['OUTBOUND_ALLOW_PRIVATE_HOSTS'] = $_SERVER['OUTBOUND_ALLOW_PRIVATE_HOSTS'] = 'false';

    try {
        $outboundConfig = require config_path('outbound.php');
    } finally {
        putenv('OUTBOUND_ALLOW_PRIVATE_HOSTS');
        unset($_ENV['OUTBOUND_ALLOW_PRIVATE_HOSTS'], $_SERVER['OUTBOUND_ALLOW_PRIVATE_HOSTS']);
    }

    expect($outboundConfig['ssrf']['allow_private_hosts'])->toBeTrue();
});

// Under the open ceiling an endpoint's own flag is what refuses: one saved without it stays off
// private hosts (qiick/laravel-outbound 0.2.0-beta.3 defaults it to false) until the product
// sets it, on create or with the toggle.
it('keeps an endpoint saved without a private-hosts flag off private hosts', function (bool $isAllowed): void {
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => $isAllowed]);

    expect(existingWebhook()->refresh()->allow_private_hosts)->toBeFalse();
})->with([
    'private urls off' => false,
    'private urls on' => true,
]);

// A failure between the destination change and the disable must leave the endpoint as it was.
it('moves and disables an endpoint in one transaction', function (): void {
    webhookSettingsGuardResolvingTo();
    $webhook = existingWebhook();
    $admin = instanceAdmin();
    Event::listen(WebhookEndpointDisabledEvent::class, function (): void {
        throw new RuntimeException('The disable failed.');
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($admin)->put(route('instance.webhooks.update', $webhook), webhookPayload([
        'url' => 'https://hooks.example.com/moved',
        'is_active' => false,
    ])))->toThrow(RuntimeException::class, 'The disable failed.');

    $unchanged = $webhook->fresh();

    expect($unchanged?->url)->toBe('https://hooks.example.com/existing')
        ->and($unchanged?->is_active)->toBeTrue();
});

// Another process may turn the setting off after this one read it: a create decides with the
// value stored when it holds the lock, never with a copy cached before.
it('validates and flags a new endpoint with the private-urls setting as stored under the lock', function (array $addresses, bool $isCreated): void {
    webhookSettingsGuardResolvingTo($addresses);
    $settings = app(SettingsRepositoryService::class);
    $settings->save(['webhooks_allow_private_urls' => true]);

    expect($settings->areWebhookPrivateUrlsAllowed())->toBeTrue();

    Setting::query()->where('key', 'webhooks_allow_private_urls')->sole()->update(['value' => false]);
    $create = fn () => app(CreateWebhookAction::class)->execute(new WebhookData('https://hooks.example.com/new', [WebhookEventEnum::UserCreated->value], true));

    if (! $isCreated) {
        expect($create)->toThrow(ValidationException::class);

        return;
    }

    expect($create()->endpoint->allow_private_hosts)->toBeFalse();
})->with([
    'a private destination' => [['10.0.0.5'], false],
    'a public destination' => [['93.184.216.34'], true],
]);
