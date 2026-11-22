<?php

declare(strict_types=1);

use App\Enums\WebhookEventEnum;
use App\Models\Webhook;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookUrlGuardService;

/**
 * The IPv6 surface of the SSRF guard (#181).
 *
 * `parse_url()` returns an IPv6 host in its URL bracket form (`[fd00:ec2::254]`) and
 * `filter_var(..., FILTER_VALIDATE_IP)` rejects that, so before the fix no IPv6 destination
 * ever reached `isBlockedAddress()` or the metadata list. Unwrapping the brackets is necessary
 * but not sufficient: the metadata list is a string comparison, so a literal must also be
 * canonicalised (`FD00:EC2:0:0:0:0:0:254`) and an IPv4-in-IPv6 literal reduced to the IPv4 it
 * carries (`::ffff:169.254.169.254`) before that comparison means anything.
 *
 * Every resolver below answers with a **public** address, so a refusal can only come from the
 * guard's own lists. Before #181 the loopback and link-local cases were refused by nothing but
 * a failing DNS lookup of the bracketed string; with a resolver that answers, they passed.
 */
function ipv6Guard(array $addresses = ['93.184.216.34']): WebhookUrlGuardService
{
    return new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static fn (string $_host): array => $addresses,
    );
}

function allowPrivateWebhookUrls(): void
{
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => true]);
}

/*
|--------------------------------------------------------------------------
| The metadata invariant: blocked whatever the setting says
|--------------------------------------------------------------------------
*/

it('keeps every spelling of the ipv6 metadata address blocked when private urls are allowed', function (string $url): void {
    allowPrivateWebhookUrls();

    expect(ipv6Guard()->validate($url))->toBe('Host is not allowed.');
})->with([
    'canonical' => 'http://[fd00:ec2::254]/latest/meta-data/',
    'uncompressed' => 'http://[FD00:EC2:0:0:0:0:0:254]/latest/meta-data/',
    'leading zeroes' => 'http://[fd00:0ec2::0254]/latest/meta-data/',
    'mixed case' => 'http://[Fd00:Ec2::254]/latest/meta-data/',
    'with a port' => 'http://[fd00:ec2::254]:80/latest/meta-data/',
    'ipv4 mapped ec2 metadata' => 'http://[::ffff:169.254.169.254]/latest/meta-data/',
    'ipv4 mapped in hextets' => 'http://[::ffff:a9fe:a9fe]/latest/meta-data/',
    'ipv4 compatible ec2 metadata' => 'http://[::169.254.169.254]/latest/meta-data/',
]);

it('keeps the ipv6 metadata address blocked with the setting off as well', function (): void {
    expect(ipv6Guard()->validate('http://[fd00:ec2::254]/latest/'))->toBe('Host is not allowed.');
});

/*
|--------------------------------------------------------------------------
| The blocklist, reached deliberately rather than by a failing lookup
|--------------------------------------------------------------------------
*/

it('refuses each blocked ipv6 range as a bracketed literal, by the guard and not the resolver', function (string $url): void {
    expect(ipv6Guard()->validate($url))->toBe('Host is not allowed.');
})->with([
    'loopback ::1' => 'http://[::1]/hook',
    'unspecified ::' => 'http://[::]/hook',
    'unspecified, long form' => 'http://[0:0:0:0:0:0:0:0]/hook',
    'link local fe80::/10' => 'http://[fe80::1]/hook',
    'link local, top of the range' => 'http://[febf:ffff::1]/hook',
    'unique local fc00::/7' => 'http://[fc00::1]/hook',
    'unique local, fd00::/8 half' => 'http://[fd00::1]/hook',
    'multicast ff00::/8' => 'http://[ff02::1]/hook',
    'documentation 2001:db8::/32' => 'http://[2001:db8::1]/hook',
    'an explicit port is parsed off the host' => 'http://[fc00::1]:8443/hook',
    'https with an explicit port' => 'https://[fe80::1]:8443/hook',
    'ipv4 mapped loopback' => 'http://[::ffff:127.0.0.1]/hook',
    'ipv4 mapped private' => 'http://[::ffff:10.0.0.5]/hook',
    'ipv4 compatible private' => 'http://[::10.0.0.5]/hook',
]);

it('lets the admin reach a private ipv6 destination once they opt in, as for ipv4', function (string $url): void {
    allowPrivateWebhookUrls();

    expect(ipv6Guard()->validate($url))->toBeNull();
})->with([
    'loopback' => 'http://[::1]/hook',
    'unique local' => 'http://[fc00::1]:8443/hook',
    'link local' => 'http://[fe80::1]/hook',
    'ipv4 mapped private' => 'http://[::ffff:10.0.0.5]/hook',
]);

/*
|--------------------------------------------------------------------------
| A bracketed host is an IPv6 literal or it is nothing
|--------------------------------------------------------------------------
*/

it('refuses a bracketed host that is not an ipv6 literal instead of treating it as a dns name', function (string $url): void {
    // The setting is on and the resolver answers, so nothing else can be doing the refusing:
    // before the fix each of these fell through to the DNS path and was authorised.
    allowPrivateWebhookUrls();

    expect(ipv6Guard()->validate($url))->toBe('Invalid URL.');
})->with([
    'a zone id, which curl would still connect to' => 'http://[fe80::1%25en0]/hook',
    'a raw zone id' => 'http://[fe80::1%en0]/hook',
    'letters that are not hextets' => 'http://[not:an:ip]/hook',
    'a hostname in brackets' => 'http://[hooks.example.com]/hook',
    'an ipv4 address in brackets' => 'http://[127.0.0.1]/hook',
    'empty brackets' => 'http://[]/hook',
    'too many hextets' => 'http://[1:2:3:4:5:6:7:8:9]/hook',
]);

it('never asks the resolver about a bracketed host at all', function (string $url): void {
    // The refusal has to be the guard's decision, not a side effect of looking up a string
    // that happens not to resolve: with the setting off, `[::1]` used to be refused only
    // because `dns_get_record('[::1]')` failed.
    $guard = new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        function (string $host): array {
            throw new RuntimeException("Guard resolved a bracketed host: {$host}");
        },
    );

    expect($guard->validate($url))->not->toBeNull();
})->with([
    'loopback' => 'http://[::1]/hook',
    'metadata' => 'http://[fd00:ec2::254]/latest/',
    'a zone id' => 'http://[fe80::1%25en0]/hook',
    'not an address' => 'http://[not:an:ip]/hook',
]);

/*
|--------------------------------------------------------------------------
| Nothing legitimate stops working
|--------------------------------------------------------------------------
*/

it('still authorises a public ipv6 literal and pins it to itself', function (): void {
    // #181 removed a nonsense CURLOPT_RESOLVE pin built from the bracketed string; since #185
    // every destination is pinned, and a literal is pinned to the address it is.
    expect(ipv6Guard()->authorizeOutbound('https://[2606:2800:220:1:248:1893:25c8:1946]/hook'))
        ->toBe(['ok' => true, 'curl_connect_to' => ['::[2606:2800:220:1:248:1893:25c8:1946]:443']]);
});

it('still authorises a public ipv4 mapped literal', function (): void {
    expect(ipv6Guard()->validate('http://[::ffff:93.184.216.34]/hook'))->toBeNull();
});

it('still pins a hostname that resolves to ipv6, brackets and all', function (): void {
    expect(ipv6Guard(['2606:2800:220:1:248:1893:25c8:1946'])->authorizeOutbound('https://v6.example.com/hook'))
        ->toBe([
            'ok' => true,
            'curl_connect_to' => ['::[2606:2800:220:1:248:1893:25c8:1946]:443'],
        ]);
});

it('refuses a hostname that resolves to a disallowed ipv6 address', function (array $addresses): void {
    expect(ipv6Guard($addresses)->validate('https://rebind.example.com/hook'))
        ->toBe('Host resolves to a disallowed address.');
})->with([
    'loopback' => [['::1']],
    'link local' => [['fe80::1']],
    'unique local' => [['fc00::1']],
    'ipv4 mapped metadata' => [['::ffff:169.254.169.254']],
    'ipv4 compatible metadata' => [['::169.254.169.254']],
    'one good answer and one bad' => [['2606:2800:220:1:248:1893:25c8:1946', '::1']],
]);

/*
|--------------------------------------------------------------------------
| The settings form, which is where #181 was reported from
|--------------------------------------------------------------------------
*/

it('refuses the ipv6 metadata endpoint at the settings form and saves nothing', function (): void {
    // The reported impact: 302 with no validation errors and the row persisted. The guard
    // here is the container's own, with the real resolver; the metadata check answers before
    // anything is looked up.
    allowPrivateWebhookUrls();

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->post(route('instance.webhooks.store'), [
            'url' => 'http://[fd00:ec2::254]/latest/meta-data/',
            'events' => [WebhookEventEnum::UserCreated->value],
            'is_active' => true,
        ])
        ->assertSessionHasErrors(['url' => 'Host is not allowed.']);

    expect(Webhook::query()->count())->toBe(0);
});
