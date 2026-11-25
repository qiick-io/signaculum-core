<?php

declare(strict_types=1);

namespace App\Services;

use Qiick\ApiKeys\Contracts\Clock;

/**
 * The application's clock (`now()`) for qiick/laravel-api-keys ({@see Clock}), so key expiry,
 * rotation grace windows and lifetime checks read the same time as the rest of Signaculum,
 * including a frozen or travelled time in tests.
 */
final class ApplicationClockService implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
