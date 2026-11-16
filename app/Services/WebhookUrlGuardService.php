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

        if (in_array($host, self::METADATA_HOSTS, true)) {
            return ['ok' => false, 'reason' => 'Host is not allowed.'];
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
            'curl_resolve' => [sprintf('%s:%d:%s', $host, $port, $pinnedAddress)],
        ];
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
        return IpUtils::checkIp($address, self::BLOCKED_NETWORKS);
    }
}
