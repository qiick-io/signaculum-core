<?php

declare(strict_types=1);

use App\Enums\WebhookEventEnum;
use App\Jobs\DeliverWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookUrlGuardService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Every way a webhook delivery refuses to leave the instance, and what it writes down when it
 * does (#180). The happy paths and the DNS-rebinding pin are in WebhookDeliveryTest.
 *
 * The SSRF guard runs at delivery time as well as at save time, because an endpoint saved when
 * its host resolved publicly can resolve to a private address by the time the job runs.
 */
function deliveryJobFor(Webhook $webhook, string $eventId = '33333333-3333-4333-8333-333333333333'): DeliverWebhookJob
{
    return new DeliverWebhookJob(
        webhookId: $webhook->getKey(),
        eventId: $eventId,
        eventType: WebhookEventEnum::UserCreated->value,
        createdAt: now()->toIso8601String(),
        data: ['id' => 'subject', 'email' => 'ada@example.com', 'name' => 'Ada'],
    );
}

function deliveryGuardResolvingTo(array $addresses): WebhookUrlGuardService
{
    return new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static fn (string $_host): array => $addresses,
    );
}

function activeWebhook(array $attributes = []): Webhook
{
    return Webhook::query()->create([
        'url' => 'https://hooks.example.com/signaculum',
        'secret' => 'test-signing-secret-value',
        'events' => [WebhookEventEnum::UserCreated->value],
        'is_active' => true,
        ...$attributes,
    ]);
}

it('sends nothing for a webhook row that has been deleted', function (): void {
    Http::fake();
    $webhook = activeWebhook();
    $job = deliveryJobFor($webhook);
    $webhook->delete();

    $job->handle(deliveryGuardResolvingTo(['93.184.216.34']));

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('sends nothing for a deactivated endpoint and records no attempt', function (): void {
    Http::fake();
    $webhook = activeWebhook(['is_active' => false]);

    deliveryJobFor($webhook)->handle(deliveryGuardResolvingTo(['93.184.216.34']));

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

it('refuses the request when the host now resolves to a private address, and says why', function (): void {
    Http::fake();
    Log::spy();
    $webhook = activeWebhook(['url' => 'https://rebound.example.com/hook']);

    deliveryJobFor($webhook)->handle(deliveryGuardResolvingTo(['10.1.2.3']));

    Http::assertNothingSent();

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_REFUSED)
        ->and($delivery->http_status)->toBeNull()
        ->and($delivery->error_message)->toBe('Host resolves to a disallowed address.')
        ->and($delivery->delivered_at)->toBeNull()
        ->and($delivery->attempt)->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Webhook delivery refused: SSRF guard'
            && $context['webhook_id'] === $webhook->uuid
            && $context['reason'] === 'Host resolves to a disallowed address.');
});

it('refuses the request when the endpoint has no signing secret', function (): void {
    Http::fake();
    $webhook = activeWebhook(['secret' => '']);

    deliveryJobFor($webhook)->handle(deliveryGuardResolvingTo(['93.184.216.34']));

    Http::assertNothingSent();

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_REFUSED)
        ->and($delivery->error_message)->toBe('Signing secret empty.')
        ->and($delivery->delivered_at)->toBeNull();
});

it('records a failed attempt and raises so the queue retries, on a non-2xx answer', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response(['error' => 'nope'], 503)]);
    $webhook = activeWebhook();

    expect(fn () => deliveryJobFor($webhook)->handle(deliveryGuardResolvingTo(['93.184.216.34'])))
        ->toThrow(RuntimeException::class, 'HTTP 503');

    $delivery = WebhookDelivery::query()->firstOrFail();

    expect($delivery->status)->toBe(WebhookDelivery::STATUS_FAILED)
        ->and($delivery->http_status)->toBe(503)
        ->and($delivery->error_message)->toBe('HTTP 503')
        ->and($delivery->delivered_at)->toBeNull()
        ->and($delivery->webhook?->is($webhook))->toBeTrue();
});

it('treats a redirect as a failure rather than following it', function (): void {
    Http::fake(['hooks.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/'])]);
    $webhook = activeWebhook();

    expect(fn () => deliveryJobFor($webhook)->handle(deliveryGuardResolvingTo(['93.184.216.34'])))
        ->toThrow(RuntimeException::class);

    expect(WebhookDelivery::query()->firstOrFail()->status)->toBe(WebhookDelivery::STATUS_FAILED);
    Http::assertSentCount(1);
});

it('logs the event once the queue gives up retrying', function (): void {
    Log::spy();
    $webhook = activeWebhook();

    deliveryJobFor($webhook)->failed(new RuntimeException('Webhook delivery failed (x): HTTP 503'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Webhook delivery exhausted retries'
            && $context['webhook_id'] === $webhook->getKey()
            && $context['message'] === 'Webhook delivery failed (x): HTTP 503');
});

it('retries three times with a growing backoff', function (): void {
    $job = deliveryJobFor(activeWebhook());

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 30, 60])
        ->and($job->timeout)->toBe(15);
});

it('refuses a url the guard will not authorise, before any request is made', function (string $url, string $reason): void {
    $guard = deliveryGuardResolvingTo(['93.184.216.34']);

    expect($guard->validate($url))->toBe($reason);
})->with([
    'empty' => ['', 'URL is required.'],
    'whitespace only' => ['   ', 'URL is required.'],
    'too long' => ['https://hooks.example.com/'.str_repeat('a', 2048), 'URL is too long.'],
    'no scheme' => ['hooks.example.com/hook', 'Invalid URL.'],
    'no host' => ['https:///hook', 'Invalid URL.'],
    'credentials in the url' => ['https://user:pass@hooks.example.com/hook', 'URL must not contain credentials.'],
    'username only' => ['https://user@hooks.example.com/hook', 'URL must not contain credentials.'],
    'file scheme' => ['file:///etc/passwd', 'Invalid URL.'],
    'gopher scheme' => ['gopher://hooks.example.com/hook', 'Only http and https URLs are allowed.'],
    'ftp scheme' => ['ftp://hooks.example.com/hook', 'Only http and https URLs are allowed.'],
    'ec2 metadata address' => ['http://169.254.169.254/latest/meta-data/', 'Host is not allowed.'],
    'gce metadata host' => ['http://metadata.google.internal/computeMetadata/', 'Host is not allowed.'],
    'bare metadata host' => ['http://metadata/computeMetadata/', 'Host is not allowed.'],
    'localhost' => ['http://localhost/hook', 'Host is not allowed.'],
    'a localhost subdomain' => ['http://api.localhost/hook', 'Host is not allowed.'],
    'an mdns name' => ['http://printer.local/hook', 'Host is not allowed.'],
    'an internal name' => ['http://redis.internal/hook', 'Host is not allowed.'],
    'a loopback literal' => ['http://127.0.0.1/hook', 'Host is not allowed.'],
    'a link local literal' => ['http://169.254.1.1/hook', 'Host is not allowed.'],
    'a cgnat literal' => ['http://100.64.0.1/hook', 'Host is not allowed.'],
]);

/**
 * `parse_url()` hands back an IPv6 literal with its brackets still on (`[fd00:ec2::254]`) and
 * `filter_var()` rejects that, so until #181 the blocklist and the metadata list were both
 * unreachable for every IPv6 destination: with the setting off a bracketed literal was refused
 * only incidentally, by the DNS lookup of the bracketed string failing, and with the setting on
 * it was authorised outright. The guard now unwraps the brackets, so both lists run.
 *
 * These two were written against #181 and un-skipped by its fix, unchanged. The resolver here
 * answers with a public address on purpose: it proves the refusal comes from the guard and not
 * from a lookup that happened to fail. The rest of the IPv6 surface is in
 * WebhookUrlGuardIpv6Test.
 */
it('keeps the ipv6 metadata host blocked when private urls are allowed', function (): void {
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => true]);

    expect(app(WebhookUrlGuardService::class)->validate('http://[fd00:ec2::254]/latest/'))
        ->toBe('Host is not allowed.');
});

it('refuses a bracketed ipv6 literal on the blocklist for the stated reason', function (string $url): void {
    expect(deliveryGuardResolvingTo(['93.184.216.34'])->validate($url))->toBe('Host is not allowed.');
})->with([
    'loopback' => 'http://[::1]/hook',
    'unique local' => 'http://[fc00::1]/hook',
    'link local' => 'http://[fe80::1]/hook',
]);

it('keeps the metadata hosts blocked even when private urls are allowed', function (): void {
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => true]);
    $guard = deliveryGuardResolvingTo(['10.0.0.5']);

    // Private destinations become reachable...
    expect($guard->validate('http://10.0.0.5/hook'))->toBeNull()
        ->and($guard->validate('http://localhost/hook'))->toBeNull()
        // ...but never the metadata services.
        ->and($guard->validate('http://169.254.169.254/latest/'))->toBe('Host is not allowed.')
        ->and($guard->validate('http://metadata.google.internal/v1/'))->toBe('Host is not allowed.');
});

it('refuses a host that does not resolve, unless private urls are allowed', function (): void {
    $guard = deliveryGuardResolvingTo([]);

    expect($guard->validate('https://nxdomain.example.com/hook'))->toBe('Host could not be resolved.');

    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => true]);

    $authorized = deliveryGuardResolvingTo([])->authorizeOutbound('https://nxdomain.example.com/hook');

    // Nothing to pin, so delivery goes out unpinned: the admin asked for private URLs.
    expect($authorized)->toBe(['ok' => true, 'curl_resolve' => []]);
});

it('prefers an ipv4 pin when a host resolves to both families', function (): void {
    $authorized = deliveryGuardResolvingTo(['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34'])
        ->authorizeOutbound('https://dual.example.com/hook');

    expect($authorized)->toBe([
        'ok' => true,
        'curl_resolve' => ['dual.example.com:443:93.184.216.34'],
    ]);
});

it('pins the port the url asked for, not only 443', function (): void {
    $guard = deliveryGuardResolvingTo(['93.184.216.34']);

    expect($guard->authorizeOutbound('http://hooks.example.com/hook')['curl_resolve'])
        ->toBe(['hooks.example.com:80:93.184.216.34'])
        ->and($guard->authorizeOutbound('https://hooks.example.com:8443/hook')['curl_resolve'])
        ->toBe(['hooks.example.com:8443:93.184.216.34']);
});

it('does not pin a literal ip, because curl already connects there', function (): void {
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => true]);

    expect(deliveryGuardResolvingTo(['93.184.216.34'])->authorizeOutbound('http://198.51.100.9/hook'))
        ->toBe(['ok' => true, 'curl_resolve' => []]);
});
