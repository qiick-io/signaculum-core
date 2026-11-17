<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Freshly issued key material: the plain-text token for the one response that shows it, the
 * public `key_prefix` the row is looked up by, and the SHA-256 of the full token to store.
 *
 * The plain-text token must never be persisted, logged or queued; only the creating or rotating
 * response may carry it.
 */
final readonly class ApiKeySecretData
{
    public function __construct(
        public string $plainTextToken,
        public string $keyPrefix,
        public string $keyHash,
    ) {}
}
