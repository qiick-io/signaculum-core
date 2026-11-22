<?php

declare(strict_types=1);

use App\Enums\WebhookEventEnum;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Policies\SettingPolicy;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookUrlGuardService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

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
    app()->bind(WebhookUrlGuardService::class, fn (): WebhookUrlGuardService => new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static fn (string $_host): array => $addresses,
    ));
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
        ->and($unchanged?->events)->toBe([WebhookEventEnum::UserCreated->value])
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
        // event_id is a uuid column (production uses Str::uuid()); non-UUID fixtures 500 on PostgreSQL (#197).
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

    $this->actingAs(instanceAdmin())->get(route('instance.webhooks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/webhooks')
            ->has('webhooks.data', 1)
            ->where('webhooks.data.0.uuid', $webhook->uuid)
            ->where('webhooks.data.0.url', 'https://hooks.example.com/existing')
            ->where('webhooks.data.0.events', [WebhookEventEnum::UserCreated->value])
            ->where('webhooks.data.0.is_active', true)
            // The resource shows at most five attempts, newest first.
            ->has('webhooks.data.0.recent_deliveries', 5)
            ->where('webhooks.data.0.recent_deliveries.0.event_id', $eventIds[6])
            ->where('webhooks.data.0.recent_deliveries.0.status', WebhookDelivery::STATUS_DELIVERED)
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

    expect($response->getContent())->not->toContain('existing-signing-secret-value');
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

    expect($shownSecret)->toBeString()->not->toBe('')
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
        ->and($unchanged?->events)->toBe([WebhookEventEnum::UserCreated->value])
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
    'secret too short' => [['url' => 'https://hooks.example.com/updated', 'events' => [WebhookEventEnum::UserUpdated->value], 'is_active' => true, 'secret' => 'tiny'], ['secret']],
    'secret too long' => [['url' => 'https://hooks.example.com/updated', 'events' => [WebhookEventEnum::UserUpdated->value], 'is_active' => true, 'secret' => str_repeat('s', 129)], ['secret']],
]);

it('runs the ssrf guard on create, so a private endpoint is never registered', function (): void {
    // Every host resolves to a private address, so no endpoint may be created.
    webhookSettingsGuardResolvingTo(['192.168.1.10']);

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->post(route('instance.webhooks.store'), webhookPayload(['url' => 'https://inside.example.com/hook']))
        ->assertSessionHasErrors('url');

    expect(Webhook::query()->count())->toBe(0);
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
    $secretBefore = $webhook->secret;

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
        ->and($updated?->events)->toBe([
            WebhookEventEnum::UserDeleted->value,
            WebhookEventEnum::ApplicationCreated->value,
        ])
        ->and($updated?->is_active)->toBeFalse()
        // An update that sends no secret keeps the one the endpoint has.
        ->and($updated?->secret)->toBe($secretBefore);
});

it('rotates the signing secret only when the update sends one', function (): void {
    webhookSettingsGuardResolvingTo();
    $webhook = existingWebhook();
    $admin = instanceAdmin();

    $this->actingAs($admin)
        ->put(route('instance.webhooks.update', $webhook), webhookPayload(['secret' => '']))
        ->assertRedirect(route('instance.webhooks.index'));

    expect($webhook->fresh()?->secret)->toBe('existing-signing-secret-value');

    $this->actingAs($admin)
        ->put(route('instance.webhooks.update', $webhook), webhookPayload(['secret' => 'a-brand-new-signing-secret']))
        ->assertRedirect(route('instance.webhooks.index'));

    expect($webhook->fresh()?->secret)->toBe('a-brand-new-signing-secret');
});

it('deletes an endpoint and its delivery history stops being listed', function (): void {
    $webhook = existingWebhook();

    $this->actingAs(instanceAdmin())
        ->delete(route('instance.webhooks.destroy', $webhook))
        ->assertRedirect(route('instance.webhooks.index'));

    expect(Webhook::query()->whereKey($webhook->getKey())->exists())->toBeFalse();
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

it('saves the private-urls toggle and the guard then honours it', function (): void {
    $settings = app(SettingsRepositoryService::class);

    expect($settings->areWebhookPrivateUrlsAllowed())->toBeFalse();

    $this->actingAs(instanceAdmin())
        ->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => '1'])
        ->assertRedirect(route('instance.webhooks.index'));

    expect(app(SettingsRepositoryService::class)->areWebhookPrivateUrlsAllowed())->toBeTrue()
        ->and(app(WebhookUrlGuardService::class)->validate('http://127.0.0.1/hook'))->toBeNull();

    $this->actingAs(instanceAdmin())
        ->put(route('instance.webhooks.private-urls.update'), ['webhooks_allow_private_urls' => '0'])
        ->assertRedirect(route('instance.webhooks.index'));

    expect(app(SettingsRepositoryService::class)->areWebhookPrivateUrlsAllowed())->toBeFalse()
        ->and(app(WebhookUrlGuardService::class)->validate('http://127.0.0.1/hook'))->not->toBeNull();
});
