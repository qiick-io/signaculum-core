<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/**
 * The outcome of a rotation, as column values: the new secret, and the replaced hash with the
 * end of its grace window. Both previous values are null when the grace window is zero.
 */
final readonly class ApiKeyRotationData
{
    public function __construct(
        public ApiKeySecretData $secret,
        public ?string $previousKeyHash,
        public ?CarbonImmutable $previousKeyValidUntil,
    ) {}
}
