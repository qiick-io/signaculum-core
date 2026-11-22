<?php

declare(strict_types=1);

namespace App\Data;

/**
 * RFC 9126 §2.2 successful PAR response payload.
 */
readonly class PushedAuthorizationRequestData
{
    public function __construct(
        public string $requestUri,
        public int $expiresIn,
    ) {}
}
