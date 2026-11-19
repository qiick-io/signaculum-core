<?php

declare(strict_types=1);

use App\Enums\WebhookEventEnum;
use App\Jobs\DeliverWebhookJob;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookUrlGuardService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * What curl connects to, and how the guard knows it checked that address (#185).
 *
 * `filter_var()` rejects `2852039166`, `0251.0376.0251.0376` and `169.254.169.254.`, but curl
 * and inet_aton(3) read each of them as 169.254.169.254. Before the fix the guard therefore
 * treated them as DNS names: authorised **unpinned** with `webhooks_allow_private_urls` on,
 * and with it off refused only when the lookup of the string happened to fail.
 *
 * The fix is to pin every destination: the guard checks the addresses it resolved (or the
 * literal it read) and hands curl exactly one of them through CURLOPT_CONNECT_TO with an empty
 * source host, which applies whatever the URL's host is spelt like. CURLOPT_RESOLVE, used before,
 * is a DNS-cache entry keyed by host name, and curl never consults it for a host it parses as
 * an IP itself, so it could not pin any of these spellings even had the guard tried.
 *
 * Every resolver below answers with a **public** address unless a test says otherwise, so a
 * refusal cannot come from a lookup that failed.
 */
function pinGuard(array $addresses = ['93.184.216.34']): WebhookUrlGuardService
{
    return new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static fn (string $_host): array => $addresses,
    );
}

function pinGuardThatMustNotResolve(): WebhookUrlGuardService
{
    return new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static function (string $host): array {
            throw new RuntimeException("Guard resolved an address literal as a name: {$host}");
        },
    );
}

function pinSetPrivateUrls(bool $isAllowed): void
{
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => $isAllowed]);
}

dataset('private url setting', [
    'setting off' => false,
    'setting on' => true,
]);

/*
|--------------------------------------------------------------------------
| Non-canonical IPv4: the metadata service in other spellings
|--------------------------------------------------------------------------
*/

dataset('metadata ipv4 spellings', [
    'decimal' => 'http://2852039166/latest/meta-data/',
    'decimal with a port' => 'http://2852039166:80/latest/meta-data/',
    'dotted octal' => 'http://0251.0376.0251.0376/latest/meta-data/',
    'dotted hex' => 'http://0xa9.0xfe.0xa9.0xfe/latest/meta-data/',
    'dotted hex, upper case' => 'http://0XA9.0XFE.0XA9.0XFE/latest/meta-data/',
    'one hex number' => 'http://0xa9fea9fe/latest/meta-data/',
    'one octal number' => 'http://025177524776/latest/meta-data/',
    'mixed bases' => 'http://169.0376.0xa9.254/latest/meta-data/',
    'three parts' => 'http://169.254.43518/latest/meta-data/',
    'two parts' => 'http://169.16689662/latest/meta-data/',
    'trailing dot' => 'http://169.254.169.254./latest/meta-data/',
    'decimal with a trailing dot' => 'http://2852039166./latest/meta-data/',
]);

it('refuses every spelling of the ipv4 metadata address, whatever the setting', function (string $url, bool $isPrivateAllowed): void {
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuard()->validate($url))->toBe('Host is not allowed.');
})->with('metadata ipv4 spellings')->with('private url setting');

it('reads an ipv4 spelling as the address it is, and never looks it up as a name', function (string $url, bool $isPrivateAllowed): void {
    // Before the fix each of these reached the resolver: that lookup failing was the only thing
    // that refused them with the setting off.
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuardThatMustNotResolve()->validate($url))->not->toBeNull();
})->with('metadata ipv4 spellings')->with('private url setting');

/*
|--------------------------------------------------------------------------
| Non-canonical IPv4 that is not the metadata address
|--------------------------------------------------------------------------
*/

it('refuses an ipv4 address that is not written in dotted decimal, whatever the setting', function (string $url, bool $isPrivateAllowed): void {
    // A webhook has no reason to spell an address any other way, and two readers can disagree
    // on these: `010.0.0.1` is 8.0.0.1 to curl and 10.0.0.1 to a decimal parser.
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuardThatMustNotResolve()->validate($url))->toBe('Invalid URL.');
})->with([
    'short form, link local (169.254.255.254)' => 'http://169.254.65534/hook',
    'short form, public (1.0.0.1)' => 'http://1.1/hook',
    'decimal loopback' => 'http://2130706433/hook',
    'hex loopback, two parts' => 'http://0x7f.1/hook',
    'short loopback' => 'http://127.1/hook',
    'octal private' => 'http://012.0.0.1/hook',
    'leading zeroes' => 'http://127.000.000.001/hook',
    'decimal public (93.184.216.34)' => 'http://1572395042/hook',
    'canonical public with a trailing dot' => 'http://93.184.216.34./hook',
])->with('private url setting');

it('still treats a name that only looks numeric as a name', function (string $url): void {
    // More than four parts, a part out of range, or a letter outside a 0x prefix: curl and
    // inet_aton(3) read these as names, so the guard resolves, checks and pins them.
    expect(pinGuard()->authorizeOutbound($url))->toBe([
        'ok' => true,
        'curl_connect_to' => ['::93.184.216.34:443'],
    ]);
})->with([
    'five parts' => 'https://1.2.3.4.5/hook',
    'a part over 255' => 'https://256.1.1.1/hook',
    'a whole address over 32 bits' => 'https://4294967296/hook',
    'a last part too big for the bytes left' => 'https://1.16777216/hook',
    'twelve significant digits' => 'https://123456789012/hook',
    'hex digits without the prefix' => 'https://cafe.1.2.3/hook',
    'an empty part' => 'https://1..2/hook',
    'a hostname made of hex letters' => 'https://deadbeef.example.com/hook',
]);

/*
|--------------------------------------------------------------------------
| A hostname that resolves to the metadata address
|--------------------------------------------------------------------------
*/

it('refuses a hostname that resolves to the metadata address, whatever the setting', function (array $addresses, bool $isPrivateAllowed): void {
    // Before the fix this was refused with the setting off only by the link-local entry in the
    // blocklist, and authorised with it on: the metadata rule looked at the host, not at
    // where it resolved.
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuard($addresses)->validate('http://metadata.attacker.example/latest/meta-data/'))
        ->toBe('Host resolves to a disallowed address.');
})->with([
    'ec2 ipv4' => [['169.254.169.254']],
    'ec2 ipv6' => [['fd00:ec2::254']],
    'ec2 ipv6, uncompressed' => [['FD00:EC2:0:0:0:0:0:254']],
    'ipv4 mapped' => [['::ffff:169.254.169.254']],
    'ipv4 compatible' => [['::169.254.169.254']],
    'behind a public answer' => [['93.184.216.34', '169.254.169.254']],
])->with('private url setting');

it('keeps the metadata names blocked with a trailing dot', function (string $url, bool $isPrivateAllowed): void {
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuard()->validate($url))->toBe('Host is not allowed.');
})->with([
    'gce metadata name' => 'http://metadata.google.internal./computeMetadata/v1/',
    'bare metadata name' => 'http://metadata./computeMetadata/v1/',
])->with('private url setting');

it('keeps the blocked names blocked with a trailing dot', function (string $url): void {
    expect(pinGuard()->validate($url))->toBe('Host is not allowed.');
})->with([
    'localhost' => 'http://localhost./hook',
    'an internal name' => 'http://redis.internal./hook',
    'an mdns name' => 'http://printer.local./hook',
]);

/*
|--------------------------------------------------------------------------
| Every authorised destination is pinned to an address the guard checked
|--------------------------------------------------------------------------
*/

it('pins every destination it authorises to exactly one checked address', function (string $url, array $addresses, bool $isPrivateAllowed, string $pin): void {
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuard($addresses)->authorizeOutbound($url))->toBe([
        'ok' => true,
        'curl_connect_to' => [$pin],
    ]);
})->with([
    'a public hostname' => ['https://hooks.example.com/hook', ['93.184.216.34'], false, '::93.184.216.34:443'],
    'a hostname over plain http' => ['http://hooks.example.com/hook', ['93.184.216.34'], false, '::93.184.216.34:80'],
    'a hostname with a port' => ['https://hooks.example.com:8443/hook', ['93.184.216.34'], false, '::93.184.216.34:8443'],
    'a fully qualified hostname' => ['https://hooks.example.com./hook', ['93.184.216.34'], false, '::93.184.216.34:443'],
    'a hostname with only ipv6' => ['https://v6.example.com/hook', ['2606:2800:220:1:248:1893:25c8:1946'], false, '::[2606:2800:220:1:248:1893:25c8:1946]:443'],
    'a public ipv4 literal' => ['http://198.51.100.9/hook', [], true, '::198.51.100.9:80'],
    'a public ipv4 literal, setting off' => ['https://93.184.216.34/hook', [], false, '::93.184.216.34:443'],
    'a public ipv6 literal' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/hook', [], false, '::[2606:2800:220:1:248:1893:25c8:1946]:443'],
    'a public ipv4 mapped literal' => ['http://[::ffff:93.184.216.34]/hook', [], false, '::[::ffff:93.184.216.34]:80'],
    'a private literal, setting on' => ['http://10.0.0.5:8080/hook', [], true, '::10.0.0.5:8080'],
    'a private ipv6 literal, setting on' => ['http://[fc00::1]:8443/hook', [], true, '::[fc00::1]:8443'],
    'a hostname answering privately, setting on' => ['http://hooks.lan.example/hook', ['10.0.0.5'], true, '::10.0.0.5:80'],
    'localhost, setting on' => ['http://localhost:9000/hook', ['127.0.0.1'], true, '::127.0.0.1:9000'],
]);

it('refuses a host that is nothing but the root dot', function (): void {
    expect(pinGuardThatMustNotResolve()->validate('http://./hook'))->toBe('Invalid URL.');
});

it('resolves and pins through the real resolver, with no resolver injected', function (): void {
    // The container's own guard: DNS, then the system resolver. `localhost` answers from the
    // machine itself, and the admin opted into private URLs, so it is authorised and pinned to
    // the loopback address the guard looked up, never left for curl to resolve.
    pinSetPrivateUrls(true);

    $authorized = app(WebhookUrlGuardService::class)->authorizeOutbound('http://localhost:9000/hook');

    expect($authorized['ok'])->toBeTrue()
        ->and($authorized['curl_connect_to'] ?? null)->toBeIn([['::127.0.0.1:9000'], ['::[::1]:9000']]);
});

it('refuses a host that does not resolve, whatever the setting, because there is nothing to pin', function (bool $isPrivateAllowed): void {
    // Before the fix the setting on authorised it with no pin, and curl then resolved the name
    // itself, to wherever its own resolver said: the path every #185 spelling took.
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuard([])->validate('https://nxdomain.example.com/hook'))->toBe('Host could not be resolved.');
})->with('private url setting');

it('never pins a resolver answer that is not an address', function (bool $isPrivateAllowed): void {
    // CURLOPT_CONNECT_TO takes a name as well as an address, and curl would resolve a name
    // itself, unchecked. Only an IP literal may become the pin.
    pinSetPrivateUrls($isPrivateAllowed);

    expect(pinGuard(['metadata.google.internal'])->validate('https://cname.example.com/hook'))
        ->toBe('Host could not be resolved.')
        ->and(pinGuard(['metadata.google.internal', '93.184.216.34'])->authorizeOutbound('https://cname.example.com/hook'))
        ->toBe(['ok' => true, 'curl_connect_to' => ['::93.184.216.34:443']]);
})->with('private url setting');

/*
|--------------------------------------------------------------------------
| Several records, and DNS rebinding
|--------------------------------------------------------------------------
*/

it('checks every record of a hostname, and pins one of them, ipv4 first', function (): void {
    $addresses = ['2606:2800:220:1:248:1893:25c8:1946', '93.184.216.34', '93.184.216.35'];

    expect(pinGuard($addresses)->authorizeOutbound('https://many.example.com/hook'))->toBe([
        'ok' => true,
        'curl_connect_to' => ['::93.184.216.34:443'],
    ])
        ->and(pinGuard([...$addresses, '10.0.0.5'])->validate('https://many.example.com/hook'))
        ->toBe('Host resolves to a disallowed address.');
});

it('resolves once per delivery and sends the request to the address it checked', function (): void {
    // DNS rebinding: the name answers publicly for the check and privately for anything after
    // it. The guard looks the name up once; curl is handed that answer and never asks again.
    $lookups = 0;
    $guard = new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static function (string $_host) use (&$lookups): array {
            $lookups++;

            return $lookups === 1 ? ['93.184.216.34'] : ['169.254.169.254'];
        },
    );

    $sentOptions = null;

    Http::fake(function (Request $request, array $options) use (&$sentOptions): PromiseInterface {
        $sentOptions = $options;

        return Http::response(['ok' => true], 200);
    });

    $webhook = Webhook::query()->create([
        'url' => 'https://rebind.example.com/hook',
        'secret' => 'test-signing-secret-value',
        'events' => [WebhookEventEnum::UserCreated->value],
        'is_active' => true,
    ]);

    $job = new DeliverWebhookJob(
        webhookId: $webhook->getKey(),
        eventId: '44444444-4444-4444-8444-444444444444',
        eventType: WebhookEventEnum::UserCreated->value,
        createdAt: now()->toIso8601String(),
        data: ['id' => 'subject'],
    );

    $job->handle($guard);

    expect($lookups)->toBe(1)
        ->and($sentOptions['curl'] ?? null)->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:443']])
        ->and(WebhookDelivery::query()->where('status', WebhookDelivery::STATUS_DELIVERED)->count())->toBe(1);

    // The next delivery looks again, gets the rebound answer, and is refused before sending.
    Http::fake();

    $job->handle($guard);

    Http::assertNothingSent();
    expect($lookups)->toBe(2)
        ->and(WebhookDelivery::query()->where('status', WebhookDelivery::STATUS_REFUSED)->value('error_message'))
        ->toBe('Host resolves to a disallowed address.');
});

it('pins a literal address in the url too, since curl reads more spellings than the guard', function (): void {
    $sentOptions = null;

    Http::fake(function (Request $request, array $options) use (&$sentOptions): PromiseInterface {
        $sentOptions = $options;

        return Http::response(['ok' => true], 200);
    });

    $webhook = Webhook::query()->create([
        'url' => 'https://93.184.216.34/hook',
        'secret' => 'test-signing-secret-value',
        'events' => [WebhookEventEnum::UserCreated->value],
        'is_active' => true,
    ]);

    (new DeliverWebhookJob(
        webhookId: $webhook->getKey(),
        eventId: '55555555-5555-4555-8555-555555555555',
        eventType: WebhookEventEnum::UserCreated->value,
        createdAt: now()->toIso8601String(),
        data: ['id' => 'subject'],
    ))->handle(pinGuardThatMustNotResolve());

    expect($sentOptions['curl'] ?? null)->toBe([CURLOPT_CONNECT_TO => ['::93.184.216.34:443']]);
});

/*
|--------------------------------------------------------------------------
| The settings form
|--------------------------------------------------------------------------
*/

it('refuses the decimal metadata address at the settings form and saves nothing', function (): void {
    // The container's own guard and resolver: the address is read before anything is looked up.
    pinSetPrivateUrls(true);

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->post(route('instance.webhooks.store'), [
            'url' => 'http://2852039166/latest/meta-data/',
            'events' => [WebhookEventEnum::UserCreated->value],
            'is_active' => true,
        ])
        ->assertSessionHasErrors(['url' => 'Host is not allowed.']);

    expect(Webhook::query()->count())->toBe(0);
});
