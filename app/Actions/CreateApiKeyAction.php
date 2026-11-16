<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;
use App\Models\User;

/**
 * Creates an Admin REST API key for an instance admin.
 *
 * Side effects: persists the hashed secret; returns the plain secret once on the model.
 */
final class CreateApiKeyAction
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>|null  $ipAllowlist
     */
    public function execute(
        User $owner,
        string $name,
        array $scopes,
        ?array $ipAllowlist = null,
        ?int $rateLimitPerMinute = null,
        ?\DateTimeInterface $expiresAt = null,
    ): ApiKey {
        $secret = ApiKey::generateSecret();

        $apiKey = ApiKey::query()->create([
            'name' => $name,
            'key_prefix' => $secret['prefix'],
            'key_hash' => $secret['hash'],
            'owner_id' => $owner->getKey(),
            'scopes' => $scopes,
            'ip_allowlist' => $ipAllowlist,
            'rate_limit_per_minute' => $rateLimitPerMinute,
            'expires_at' => $expiresAt,
        ]);

        $apiKey->setAttribute('plain_secret', $secret['plain']);

        return $apiKey;
    }
}
