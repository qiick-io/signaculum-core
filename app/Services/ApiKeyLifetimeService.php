<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Qiick\ApiKeys\Contracts\LifetimePolicy;
use RuntimeException;

/**
 * The bounded lifetime of Admin API keys: every key expires, at most `api.key_max_lifetime_days`
 * after it was created or last rotated (decision 2026-11-22 on the instance_service principal).
 *
 * A key that never expires outlives the people who issued it; the bound forces each key back
 * to an instance admin, who rotates or replaces it, at least once per lifetime.
 */
final class ApiKeyLifetimeService implements LifetimePolicy
{
    /**
     * The ceiling set by the decision: configuration may shorten the lifetime, never extend it.
     */
    public const MAXIMUM_LIFETIME_DAYS = 90;

    /**
     * The configured lifetime (`api.key_max_lifetime_days`, the ceiling when unset). Checked at
     * boot, so an instance configured above the ceiling or below one day refuses to start.
     *
     * @throws RuntimeException when the configured value is not a whole number of days from 1 to the ceiling
     */
    public function maximumLifetimeDays(): int
    {
        $configuredDays = config('api.key_max_lifetime_days') ?? self::MAXIMUM_LIFETIME_DAYS;
        $lifetimeDays = filter_var($configuredDays, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => self::MAXIMUM_LIFETIME_DAYS],
        ]);

        if ($lifetimeDays === false) {
            throw new RuntimeException('API_KEY_MAX_LIFETIME_DAYS must be a whole number of days from 1 to '.self::MAXIMUM_LIFETIME_DAYS.'.');
        }

        return $lifetimeDays;
    }

    /**
     * qiick/laravel-api-keys' lifetime check on rotation ({@see LifetimePolicy}): every key
     * expires, within the configured maximum lifetime from `$now`.
     */
    public function allowsExpiry(?\DateTimeImmutable $expiresAt, \DateTimeImmutable $now): bool
    {
        return $expiresAt !== null
            && $expiresAt > $now
            && $expiresAt <= CarbonImmutable::instance($now)->addDays($this->maximumLifetimeDays());
    }

    /**
     * The latest expiry a key created or rotated at `$issuedAt` may have; the limit itself is allowed.
     */
    public function latestExpiryFor(CarbonImmutable $issuedAt): CarbonImmutable
    {
        return $issuedAt->addDays($this->maximumLifetimeDays());
    }

    /**
     * The expiry to store, in the application timezone: the `datetime` cast writes the wall-clock
     * value without converting it, so an expiry sent with another offset would be stored, and
     * later read, as a different instant from the one checked here.
     *
     * @throws InvalidArgumentException when the expiry is not after `$issuedAt` or past the maximum lifetime
     */
    public function allowedExpiry(CarbonImmutable $expiresAt, CarbonImmutable $issuedAt): CarbonImmutable
    {
        $expiresAt = $expiresAt->setTimezone(config()->string('app.timezone'));

        if (! $expiresAt->isAfter($issuedAt)) {
            throw new InvalidArgumentException('An API key must expire in the future.');
        }

        if ($expiresAt->isAfter($this->latestExpiryFor($issuedAt))) {
            throw new InvalidArgumentException("An API key may last at most {$this->maximumLifetimeDays()} days.");
        }

        return $expiresAt;
    }
}
