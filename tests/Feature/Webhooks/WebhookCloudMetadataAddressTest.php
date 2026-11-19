<?php

declare(strict_types=1);

use App\Enums\WebhookEventEnum;
use App\Models\Webhook;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookUrlGuardService;

/**
 * Cloud metadata services outside the link-local range (#190).
 *
 * Alibaba's 100.100.100.200 and Oracle's 192.0.0.192 are covered by the private-range
 * blocklist while `webhooks_allow_private_urls` is off, but that blocklist is deliberately
 * bypassed when an administrator opts in. Only the metadata list stays blocked in both
 * settings, so both addresses belong there alongside the AWS and GCE entries.
 *
 * The resolver answers with a public address: a refusal must come from the metadata rule,
 * not from a lookup that failed. Every alternate IPv4 spelling the guard reads as an address
 * must be refused for the same reason, and so must the IPv4-in-IPv6 forms (#181, #189).
 */
function cloudMetadataGuard(array $addresses = ['93.184.216.34']): WebhookUrlGuardService
{
    return new WebhookUrlGuardService(
        app(SettingsRepositoryService::class),
        static fn (string $_host): array => $addresses,
    );
}

function setCloudMetadataPrivateUrls(bool $isAllowed): void
{
    app(SettingsRepositoryService::class)->save(['webhooks_allow_private_urls' => $isAllowed]);
}

dataset('cloud metadata private url setting', [
    'setting off' => false,
    'setting on' => true,
]);

dataset('cloud metadata address urls', [
    'alibaba dotted decimal' => 'http://100.100.100.200/latest/meta-data/',
    'alibaba trailing dot' => 'http://100.100.100.200./latest/meta-data/',
    'alibaba decimal' => 'http://1684301000/latest/meta-data/',
    'alibaba dotted octal' => 'http://0144.0144.0144.0310/latest/meta-data/',
    'alibaba dotted hex' => 'http://0x64.0x64.0x64.0xc8/latest/meta-data/',
    'alibaba one hex number' => 'http://0x646464c8/latest/meta-data/',
    'alibaba one octal number' => 'http://014431062310/latest/meta-data/',
    'alibaba mixed bases' => 'http://0x64.0144.100.0310/latest/meta-data/',
    'alibaba three parts' => 'http://100.100.25800/latest/meta-data/',
    'alibaba two parts' => 'http://100.6579400/latest/meta-data/',
    'oracle dotted decimal' => 'http://192.0.0.192/opc/v1/instance/',
    'oracle trailing dot' => 'http://192.0.0.192./opc/v1/instance/',
    'oracle decimal' => 'http://3221225664/opc/v1/instance/',
    'oracle dotted octal' => 'http://0300.0.0.0300/opc/v1/instance/',
    'oracle dotted hex' => 'http://0xc0.0.0.0xc0/opc/v1/instance/',
    'oracle one hex number' => 'http://0xc00000c0/opc/v1/instance/',
    'oracle one octal number' => 'http://030000000300/opc/v1/instance/',
    'oracle mixed bases' => 'http://0xc0.0.0.0300/opc/v1/instance/',
    'oracle three parts' => 'http://192.0.192/opc/v1/instance/',
    'oracle two parts' => 'http://192.192/opc/v1/instance/',
    'alibaba ipv4 mapped' => 'http://[::ffff:100.100.100.200]/latest/meta-data/',
    'alibaba ipv4 mapped as hextets' => 'http://[::ffff:6464:64c8]/latest/meta-data/',
    'alibaba ipv4 compatible' => 'http://[::100.100.100.200]/latest/meta-data/',
    'oracle ipv4 mapped' => 'http://[::ffff:192.0.0.192]/opc/v1/instance/',
    'oracle ipv4 mapped as hextets' => 'http://[::ffff:c000:c0]/opc/v1/instance/',
    'oracle ipv4 compatible' => 'http://[::192.0.0.192]/opc/v1/instance/',
]);

it('refuses every alibaba and oracle metadata spelling, whatever the setting', function (string $url, bool $isPrivateAllowed): void {
    setCloudMetadataPrivateUrls($isPrivateAllowed);

    expect(cloudMetadataGuard()->validate($url))->toBe('Host is not allowed.');
})->with('cloud metadata address urls')->with('cloud metadata private url setting');

it('refuses a name resolving to an alibaba or oracle metadata address, whatever the setting', function (string $address, bool $isPrivateAllowed): void {
    setCloudMetadataPrivateUrls($isPrivateAllowed);

    expect(cloudMetadataGuard([$address])->validate('https://metadata.example.com/latest/'))
        ->toBe('Host resolves to a disallowed address.');
})->with([
    'alibaba' => '100.100.100.200',
    'oracle' => '192.0.0.192',
])->with('cloud metadata private url setting');

it('refuses the new metadata addresses at the settings form even with private urls allowed', function (string $url): void {
    setCloudMetadataPrivateUrls(true);

    $this->actingAs(instanceAdmin())
        ->from(route('instance.webhooks.index'))
        ->post(route('instance.webhooks.store'), [
            'url' => $url,
            'events' => [WebhookEventEnum::UserCreated->value],
            'is_active' => true,
        ])
        ->assertSessionHasErrors(['url' => 'Host is not allowed.']);

    expect(Webhook::query()->count())->toBe(0);
})->with([
    'alibaba' => 'http://100.100.100.200/latest/meta-data/',
    'oracle' => 'http://192.0.0.192/opc/v1/instance/',
]);
