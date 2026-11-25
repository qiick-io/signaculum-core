<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\ApiKeyData;
use App\Data\IssuedApiKeyData;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyLifetimeService;
use App\Services\ApiKeyScopeVocabularyService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Qiick\ApiKeys\ScopeSet;
use Qiick\ApiKeys\TokenCodec;

/**
 * Creates an Admin REST API key owned by the singleton `instance_service` principal.
 *
 * Only an active instance admin may create one, with this product's instance scopes
 * (`users`, `organizations`, `applications`). `created_by` is the issuing admin;
 * `owner_principal_id` is the instance service account (decision 2026-11-22 / #229). Every key
 * expires within the maximum lifetime of {@see ApiKeyLifetimeService}, counted from now.
 *
 * Side effects: stores the row (SHA-256 of the full token only) and writes a `created` entry in the
 * `api-keys` activity log, caused by the issuer. The plain-text token is returned once, in
 * {@see IssuedApiKeyData}, and is never set on the model.
 */
final class CreateApiKeyAction
{
    public function __construct(
        private readonly TokenCodec $tokenCodec,
        private readonly ApiKeyScopeVocabularyService $apiKeyScopeVocabularyService,
        private readonly EnsureInstanceServicePrincipalAction $ensureInstanceServicePrincipalAction,
        private readonly ApiKeyLifetimeService $apiKeyLifetimeService,
    ) {}

    /**
     * @throws AuthorizationException when the issuer may not create keys
     * @throws InvalidArgumentException when the scopes are empty or outside the vocabulary, or the
     *                                  expiry is not in the future or past the maximum lifetime
     */
    public function execute(User $issuer, ApiKeyData $apiKeyData): IssuedApiKeyData
    {
        Gate::forUser($issuer)->authorize('create', ApiKey::class);

        $grantedScopes = ScopeSet::parseStored($apiKeyData->scopes, $this->apiKeyScopeVocabularyService);

        if ($grantedScopes->isEmpty()) {
            throw new InvalidArgumentException('An API key needs at least one known scope.');
        }

        $expiresAt = $this->apiKeyLifetimeService->allowedExpiry($apiKeyData->expiresAt, CarbonImmutable::now());

        $secret = $this->tokenCodec->issue();
        $instancePrincipal = $this->ensureInstanceServicePrincipalAction->execute();

        $apiKey = DB::transaction(function () use ($issuer, $apiKeyData, $grantedScopes, $secret, $instancePrincipal, $expiresAt): ApiKey {
            $apiKey = (new ApiKey)->forceFill([
                'name' => $apiKeyData->name,
                'key_prefix' => $secret->keyPrefix,
                'key_hash' => $secret->keyHash,
                'token_version' => $secret->tokenVersion,
                // Attribution / FK: the issuing admin. Ownership for auth is the principal.
                'owner_id' => $issuer->getKey(),
                'owner_principal_id' => $instancePrincipal->getKey(),
                'created_by' => $issuer->getKey(),
                'scopes' => $grantedScopes->values(),
                'ip_allowlist' => $apiKeyData->ipAllowlist,
                'rate_limit_per_minute' => $apiKeyData->rateLimitPerMinute,
                'expires_at' => $expiresAt,
            ]);
            $apiKey->save();

            activity('api-keys')
                ->causedBy($issuer)
                ->performedOn($apiKey)
                ->event('created')
                ->withProperties(['name' => $apiKey->name, 'scopes' => $grantedScopes->values()])
                ->log('API key created');

            return $apiKey;
        });

        return new IssuedApiKeyData($apiKey, $secret->plainText);
    }
}
