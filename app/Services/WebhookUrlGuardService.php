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
        $trimmed = trim($url);

        if ($trimmed === '') {
            return 'URL is required.';
        }

        if (strlen($trimmed) > 2048) {
            return 'URL is too long.';
        }

        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Invalid URL.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'URL must not contain credentials.';
        }

        $scheme = strtolower((string) $parts['scheme']);

        if (! in_array($scheme, ['https', 'http'], true)) {
            return 'Only http and https URLs are allowed.';
        }

        $host = strtolower((string) $parts['host']);
        $isPrivateAllowed = $this->settings->areWebhookPrivateUrlsAllowed();

        if (in_array($host, self::METADATA_HOSTS, true)) {
            return 'Host is not allowed.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! $isPrivateAllowed && $this->isBlockedAddress($host)) {
                return 'Host is not allowed.';
            }

            return null;
        }

        if (! $isPrivateAllowed && $this->isBlockedHostName($host)) {
            return 'Host is not allowed.';
        }

        $resolvedAddresses = $this->resolveHostAddresses($host);

        if ($resolvedAddresses === [] && ! $isPrivateAllowed) {
            return 'Host could not be resolved.';
        }

        if (! $isPrivateAllowed) {
            foreach ($resolvedAddresses as $address) {
                if ($this->isBlockedAddress($address)) {
                    return 'Host resolves to a disallowed address.';
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveHostAddresses(string $host): array
    {
        $resolver = $this->hostResolver ?? static function (string $name): array {
            $addresses = gethostbynamel($name);

            return is_array($addresses) ? $addresses : [];
        };

        return $resolver($host);
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
