<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;

/**
 * Revokes an Admin REST API key so further Bearer requests fail.
 */
final class RevokeApiKeyAction
{
    public function execute(ApiKey $apiKey): void
    {
        if ($apiKey->revoked_at !== null) {
            return;
        }

        $apiKey->forceFill(['revoked_at' => now()])->save();
    }
}
