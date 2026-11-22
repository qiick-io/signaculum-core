<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordApiKeyUsageAction;
use App\Enums\PermissionEnum;
use App\Http\Responses\BearerTokenErrorResponse;
use App\Models\ApiKey;
use App\Services\ApiKeySecretService;
use App\Services\Authorization\EffectivePermissionResolverService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Admin REST API requests with a Bearer API key, failing closed.
 *
 * Order matters: the token is verified before the key's state is reported, so knowing a public
 * `key_prefix` (shown in the UI and in logs) tells nothing about the key. An unknown key and a
 * wrong secret get the same answer. Lookups are never cached: a revoke applies to the next request.
 *
 * Side effects: authenticates the key owner on the separate `api-key` guard (never the session
 * guard, so no web session can come of it), puts the key on the request as `apiKey`, records
 * `last_used_at` / `last_used_ip`, enforces the per-key rate limit and applies the owner's locale
 * when no supported `Accept-Language` was requested.
 */
final class AuthenticateApiKeyMiddleware
{
    public function __construct(
        private readonly ApiKeySecretService $apiKeySecretService,
        private readonly RecordApiKeyUsageAction $recordApiKeyUsageAction,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if ($plainTextToken === null || $plainTextToken === '') {
            return BearerTokenErrorResponse::invalidToken(__('Missing API key.'));
        }

        $apiKey = $this->findMatchingApiKey($plainTextToken);

        if ($apiKey === null) {
            return BearerTokenErrorResponse::invalidToken(__('Invalid API key.'));
        }

        if ($apiKey->isRevoked()) {
            return BearerTokenErrorResponse::invalidToken(__('API key has been revoked.'));
        }

        if ($apiKey->isExpired()) {
            return BearerTokenErrorResponse::invalidToken(__('API key has expired.'));
        }

        if ($apiKey->grantedScopes() === null) {
            return BearerTokenErrorResponse::invalidToken(__('API key has no valid scopes.'));
        }

        // A key never does more than its owner: a deactivated or demoted admin stops their keys.
        $owner = $apiKey->owner;

        if ($owner === null || ! $owner->is_active || ! app(EffectivePermissionResolverService::class)->hasPermission($owner, PermissionEnum::CanManageApiKeys->value)) {
            return BearerTokenErrorResponse::invalidToken(__('API key owner may not use the API.'));
        }

        if (! $apiKey->allowsIp((string) $request->ip())) {
            return response()->json(['message' => __('API key is not allowed from this IP address.')], 403);
        }

        if ($this->isOverRateLimit($apiKey)) {
            return response()->json(['message' => __('API key rate limit exceeded.')], 429);
        }

        $this->recordApiKeyUsageAction->execute($apiKey, (string) $request->ip());

        $request->attributes->set('apiKey', $apiKey);
        Auth::guard('api-key')->setUser($owner);
        Auth::shouldUse('api-key');

        // The global locale middleware ran before key auth; apply the owner's locale here.
        SetLocaleMiddleware::applyPrincipalLocale($request, is_string($owner->locale) ? $owner->locale : null);

        return $next($request);
    }

    private function findMatchingApiKey(string $plainTextToken): ?ApiKey
    {
        $keyPrefix = $this->apiKeySecretService->keyPrefixOf($plainTextToken);

        if ($keyPrefix === null) {
            return null;
        }

        $apiKey = ApiKey::query()->with('owner')->where('key_prefix', $keyPrefix)->first();

        if ($apiKey === null) {
            return null;
        }

        $isMatchingToken = $this->apiKeySecretService->matches(
            $plainTextToken,
            $apiKey->key_hash,
            $apiKey->previous_key_hash,
            $apiKey->previous_key_valid_until,
            now(),
        );

        return $isMatchingToken ? $apiKey : null;
    }

    /**
     * Counts this request against the key's own limit (`rate_limit_per_minute`, or
     * `api.default_rate_limit_per_minute` when the key has none). A non-positive stored value is
     * not "unlimited": it falls back to the default. Only a default of 0 turns the limit off.
     */
    private function isOverRateLimit(ApiKey $apiKey): bool
    {
        $limit = $apiKey->rate_limit_per_minute !== null && $apiKey->rate_limit_per_minute > 0
            ? $apiKey->rate_limit_per_minute
            : (int) config('api.default_rate_limit_per_minute', 60);

        if ($limit <= 0) {
            return false;
        }

        $rateLimiterKey = 'api-key:'.$apiKey->getKey();

        if (RateLimiter::tooManyAttempts($rateLimiterKey, $limit)) {
            return true;
        }

        RateLimiter::hit($rateLimiterKey, 60);

        return false;
    }
}
