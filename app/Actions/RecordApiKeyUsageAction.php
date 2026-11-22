<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;

/**
 * Stamps when and from where an API key was last used.
 *
 * Writes at most once a minute per key, so a busy integration does not write on every request,
 * except when the address changes: a key used from somewhere new shows at once. Saved quietly,
 * so usage never lands in the model activity log.
 */
final class RecordApiKeyUsageAction
{
    public function execute(ApiKey $apiKey, string $ipAddress): void
    {
        $isRecentFromSameAddress = $apiKey->last_used_at !== null
            && $apiKey->last_used_at->greaterThan(now()->subMinute())
            && $apiKey->last_used_ip === $ipAddress;

        if ($isRecentFromSameAddress) {
            return;
        }

        $apiKey->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ipAddress,
        ])->saveQuietly();
    }
}
