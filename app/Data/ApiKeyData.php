<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\ApiKeyLifetimeService;
use Carbon\CarbonImmutable;

/**
 * A validated API key to create: what it is for, what it may do, when it expires and its
 * optional restrictions. `scopes` holds one scope per resource (see
 * {@see ApiKeyScopesData::highestPerResource()}). The expiry is mandatory and bounded by
 * {@see ApiKeyLifetimeService}.
 */
final readonly class ApiKeyData
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $ipAllowlist  addresses and CIDR ranges; null allows every address
     */
    public function __construct(
        public string $name,
        public array $scopes,
        public CarbonImmutable $expiresAt,
        public ?array $ipAllowlist = null,
        public ?int $rateLimitPerMinute = null,
    ) {}
}
