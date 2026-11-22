<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ApiKey;

/**
 * A created or rotated key together with its plain-text token, which exists only in this
 * object: it is never set on the model, so saving or logging the model cannot leak it.
 */
final readonly class IssuedApiKeyData
{
    public function __construct(
        public ApiKey $apiKey,
        public string $plainTextToken,
    ) {}
}
