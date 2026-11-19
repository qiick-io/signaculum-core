<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for outbound webhook URLs.
 *
 * Refuses credentials in the URL, non-http(s) schemes, and private / loopback /
 * link-local / CGNAT / metadata addresses unless the instance setting
 * `webhooks_allow_private_urls` is on. Metadata hosts stay blocked even then.
 *
 * Hostnames are resolved for both A and AAAA; if any address is disallowed the
 * URL is refused. Delivery pins one validated address via CURLOPT_RESOLVE so a
 * later DNS rebinding answer cannot redirect the HTTP connection.
 *
 * An IPv6 literal arrives from `parse_url()` in URL bracket form and is unwrapped
 * and canonicalised before any check, so it is compared as an address and never
 * mistaken for a DNS name (#181). A bracketed host that is not a valid IPv6
 * literal is refused outright rather than handed to the resolver.
 */
final class WebhookUrlGuardService
{
    /**
     * @var list<string>
     */
    private const array BLOCKED_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '::/128',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
        '2001:db8::/32',
    ];

    /**
     * @var list<string>
     */
    private const array METADATA_HOSTS = [
        'metadata',
        'metadata.google.internal',
        '169.254.169.254',
        'fd00:ec2::254',
    ];

    /**
     * @param  (Closure(string): list<string>)|null  $hostResolver
     */
    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly ?Closure $hostResolver = null,
    ) {}

    /**
     * @return string|null English error when the URL must not be called; null when safe
     */
    public function validate(string $url): ?string
    {
        $authorized = $this->authorizeOutbound($url);

        return $authorized['ok'] ? null : $authorized['reason'];
    }

    /**
     * Validate the URL once and return CURLOPT_RESOLVE entries that pin the checked IP.
     *
     * @return array{ok: true, curl_resolve: list<string>}|array{ok: false, reason: string}
     */
    public function authorizeOutbound(string $url): array
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return ['ok' => false, 'reason' => 'URL is required.'];
        }

        if (strlen($trimmed) > 2048) {
            return ['ok' => false, 'reason' => 'URL is too long.'];
        }

        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return ['ok' => false, 'reason' => 'Invalid URL.'];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'reason' => 'URL must not contain credentials.'];
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['https', 'http'], true)) {
            return ['ok' => false, 'reason' => 'Only http and https URLs are allowed.'];
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);
        $isPrivateAllowed = $this->settings->areWebhookPrivateUrlsAllowed();

        // `parse_url()` hands back an IPv6 host with its brackets on; they are URL syntax,
        // not part of the address, and every check below wants the address (#181).
        if (str_contains($host, '[') || str_contains($host, ']')) {
            $literal = $this->unwrapIpv6Literal($host);

            // Brackets mean IPv6 and nothing else (RFC 3986 §3.2.2). Anything else in them
            // is refused here; letting it fall through to the resolver as a name is how a
            // zone id such as `[fe80::1%25en0]` reached curl unchecked.
            if ($literal === null) {
                return ['ok' => false, 'reason' => 'Invalid URL.'];
            }

            $host = $literal;
        }

        foreach ($this->addressForms($host) as $form) {
            if (in_array($form, self::METADATA_HOSTS, true)) {
                return ['ok' => false, 'reason' => 'Host is not allowed.'];
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $isPrivateAllowed && $this->isBlockedAddress($host)) {
                return ['ok' => false, 'reason' => 'Host is not allowed.'];
            }

            // Literal IP in the URL — curl already connects there; no resolve pin.
            return ['ok' => true, 'curl_resolve' => []];
        }

        if (! $isPrivateAllowed && $this->isBlockedHostName($host)) {
            return ['ok' => false, 'reason' => 'Host is not allowed.'];
        }

        $resolvedAddresses = $this->resolveHostAddresses($host);

        if ($resolvedAddresses === []) {
            if (! $isPrivateAllowed) {
                return ['ok' => false, 'reason' => 'Host could not be resolved.'];
            }

            // Admin opted into private URLs and DNS returned nothing — cannot pin.
            return ['ok' => true, 'curl_resolve' => []];
        }

        if (! $isPrivateAllowed) {
            foreach ($resolvedAddresses as $address) {
                if ($this->isBlockedAddress($address)) {
                    return ['ok' => false, 'reason' => 'Host resolves to a disallowed address.'];
                }
            }
        }

        $pinnedAddress = $this->preferredPinnedAddress($resolvedAddresses);

        return [
            'ok' => true,
            'curl_resolve' => [sprintf('%s:%d:%s', $host, $port, $this->formatCurlResolveAddress($pinnedAddress))],
        ];
    }

    /**
     * The address inside a URL's IPv6 brackets, canonicalised, or null when the bracketed
     * host is not a valid IPv6 literal.
     *
     * Canonicalising matters as much as unwrapping: METADATA_HOSTS and the blocklist are
     * compared as text, so `[FD00:EC2:0:0:0:0:0:254]` would miss the metadata entry that
     * `[fd00:ec2::254]` hits. `filter_var()` rejects a zone id, an IPv4 address and a
     * hostname in brackets, all of which therefore return null.
     */
    private function unwrapIpv6Literal(string $host): ?string
    {
        if (! str_starts_with($host, '[') || ! str_ends_with($host, ']')) {
            return null;
        }

        $literal = substr($host, 1, -1);

        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }

        $packed = inet_pton($literal);

        return $packed === false ? $literal : (string) inet_ntop($packed);
    }

    /**
     * Every address a host denotes: itself, plus the IPv4 address an IPv6 literal carries.
     *
     * `::ffff:169.254.169.254` (IPv4-mapped, RFC 4291 §2.5.5.2) and `::169.254.169.254`
     * (IPv4-compatible, §2.5.5.1) reach the same host as the IPv4 address inside them, and
     * neither spelling matches an IPv4 entry as text. IpUtils unwraps the mapped form against
     * an IPv4 subnet but not the compatible one, and neither form can match METADATA_HOSTS,
     * so both are expanded here instead.
     *
     * @return list<string>
     */
    private function addressForms(string $address): array
    {
        $packed = @inet_pton($address);

        if ($packed === false || strlen($packed) !== 16) {
            return [$address];
        }

        $prefix = substr($packed, 0, 12);

        if ($prefix !== str_repeat("\0", 10)."\xff\xff" && $prefix !== str_repeat("\0", 12)) {
            return [$address];
        }

        $embedded = inet_ntop(substr($packed, 12));

        return $embedded === false ? [$address] : [$address, $embedded];
    }

    /**
     * CURLOPT_RESOLVE needs brackets around an IPv6 pin (`host:443:[2001:db8::1]`).
     */
    private function formatCurlResolveAddress(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.$address.']';
        }

        return $address;
    }

    /**
     * @return list<string>
     */
    private function resolveHostAddresses(string $host): array
    {
        $resolver = $this->hostResolver ?? static function (string $name): array {
            $records = dns_get_record($name, DNS_A | DNS_AAAA);

            if ($records === false) {
                return [];
            }

            $addresses = [];

            foreach ($records as $record) {
                $type = $record['type'] ?? null;

                if ($type === 'A' && isset($record['ip']) && is_string($record['ip'])) {
                    $addresses[] = $record['ip'];
                }

                if ($type === 'AAAA' && isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }

            return array_values(array_unique($addresses));
        };

        /** @var list<string> $addresses */
        $addresses = $resolver($host);

        return $addresses;
    }

    /**
     * Prefer an IPv4 pin when both families were validated (curl + Http client quirks).
     *
     * @param  non-empty-list<string>  $addresses
     */
    private function preferredPinnedAddress(array $addresses): string
    {
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return $addresses[0];
    }

    private function isBlockedHostName(string $host): bool
    {
        return $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    private function isBlockedAddress(string $address): bool
    {
        foreach ($this->addressForms($address) as $form) {
            if (IpUtils::checkIp($form, self::BLOCKED_NETWORKS)) {
                return true;
            }
        }

        return false;
    }
}
