<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The bounded lifetime of Admin API keys: every key expires, at most `api.key_max_lifetime_days`
 * after it was created or last rotated (decision 2026-11-22 on the instance_service principal).
 *
 * A key that never expires outlives the people who issued it; the bound forces each key back
 * to an instance admin, who rotates or replaces it, at least once per lifetime.
 */
final class ApiKeyLifetimeService
{
    public function maximumLifetimeDays(): int
    {
        return max(1, (int) config('api.key_max_lifetime_days', 90));
    }

    /**
     * The latest expiry a key created or rotated at `$issuedAt` may have; the limit itself is allowed.
     */
    public function latestExpiryFor(CarbonImmutable $issuedAt): CarbonImmutable
    {
        return $issuedAt->addDays($this->maximumLifetimeDays());
    }

    /**
     * @throws InvalidArgumentException when the expiry is not after `$issuedAt` or past the maximum lifetime
     */
    public function ensureAllowedExpiry(CarbonImmutable $expiresAt, CarbonImmutable $issuedAt): void
    {
        if (! $expiresAt->isAfter($issuedAt)) {
            throw new InvalidArgumentException('An API key must expire in the future.');
        }

        if ($expiresAt->isAfter($this->latestExpiryFor($issuedAt))) {
            throw new InvalidArgumentException("An API key may last at most {$this->maximumLifetimeDays()} days.");
        }
    }
}
