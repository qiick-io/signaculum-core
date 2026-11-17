<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApiKeyData;
use App\Data\ApiKeyScopesData;
use App\Data\IssuedApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeySecretService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Creates an Admin REST API key that acts as the instance admin who issues it.
 *
 * A key never holds more than its issuer: only an active instance admin (who holds every Admin
 * API scope) may create one, and only with scopes of this product's vocabulary. Key management
 * is reached through the session-authenticated admin UI only; no API route leads here.
 *
 * Side effects: stores the row (SHA-256 of the full token only) and writes a `created` entry in the
 * `api-keys` activity log, caused by the issuer. The plain-text token is returned once, in
 * {@see IssuedApiKeyData}, and is never set on the model.
 */
final class CreateApiKeyAction
{
    public function __construct(
        private readonly ApiKeySecretService $apiKeySecretService,
    ) {}

    /**
     * @throws AuthorizationException when the issuer may not create keys
     * @throws InvalidArgumentException when the scopes are empty or outside the vocabulary
     */
    public function execute(User $issuer, ApiKeyData $apiKeyData): IssuedApiKeyData
    {
        Gate::forUser($issuer)->authorize('create', ApiKey::class);

        $grantedScopes = ApiKeyScopesData::fromStored($apiKeyData->scopes, ApiKeyScopeEnum::values());

        if ($grantedScopes === null) {
            throw new InvalidArgumentException('An API key needs at least one known scope.');
        }

        $secret = $this->apiKeySecretService->issue();

        $apiKey = DB::transaction(function () use ($issuer, $apiKeyData, $grantedScopes, $secret): ApiKey {
            $apiKey = ApiKey::query()->create([
                'name' => $apiKeyData->name,
                'key_prefix' => $secret->keyPrefix,
                'key_hash' => $secret->keyHash,
                'owner_id' => $issuer->getKey(),
                'created_by' => $issuer->getKey(),
                'scopes' => $grantedScopes->scopes,
                'ip_allowlist' => $apiKeyData->ipAllowlist,
                'rate_limit_per_minute' => $apiKeyData->rateLimitPerMinute,
                'expires_at' => $apiKeyData->expiresAt,
            ]);

            activity('api-keys')
                ->causedBy($issuer)
                ->performedOn($apiKey)
                ->event('created')
                ->withProperties(['name' => $apiKey->name, 'scopes' => $grantedScopes->scopes])
                ->log('API key created');

            return $apiKey;
        });

        return new IssuedApiKeyData($apiKey, $secret->plainTextToken);
    }
}
