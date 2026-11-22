<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordApiKeyUsageAction;
use App\Enums\PermissionEnum;
use App\Enums\PrincipalTypeEnum;
use App\Http\Responses\BearerTokenErrorResponse;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeySecretService;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\InstanceAdminPresenceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Admin REST API requests with a Bearer API key, failing closed.
 *
 * Keys are owned by the singleton `instance_service` principal. They remain valid while
 * that principal is active and at least one active instance admin still exists; demoting
 * the creating admin alone does not revoke them (decision 2026-11-22 / #229).
 *
 * Side effects: authenticates an acting admin on the separate `api-key` guard (never the
 * session guard), puts the key on the request as `apiKey`, records `last_used_at` /
 * `last_used_ip`, enforces the per-key rate limit and applies the actor's locale when no
 * supported `Accept-Language` was requested.
 */
final class AuthenticateApiKeyMiddleware
{
    public function __construct(
        private readonly ApiKeySecretService $apiKeySecretService,
        private readonly RecordApiKeyUsageAction $recordApiKeyUsageAction,
        private readonly InstanceAdminPresenceService $instanceAdminPresenceService,
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

        $ownerPrincipal = $apiKey->ownerPrincipal;

        if (
            $ownerPrincipal === null
            || $ownerPrincipal->type !== PrincipalTypeEnum::InstanceService
            || $ownerPrincipal->deactivated_at !== null
        ) {
            return BearerTokenErrorResponse::invalidToken(__('API key owner may not use the API.'));
        }

        if (! $this->instanceAdminPresenceService->hasActiveInstanceAdmin()) {
            return BearerTokenErrorResponse::invalidToken(__('API key owner may not use the API.'));
        }

        $actingAdmin = $this->resolveActingAdmin($apiKey);

        if ($actingAdmin === null) {
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
        Auth::guard('api-key')->setUser($actingAdmin);
        Auth::shouldUse('api-key');

        // The global locale middleware ran before key auth; apply the actor's locale here.
        SetLocaleMiddleware::applyPrincipalLocale($request, is_string($actingAdmin->locale) ? $actingAdmin->locale : null);

        return $next($request);
    }

    /**
     * Prefer the creating admin while they stay an active instance admin; otherwise any other.
     */
    private function resolveActingAdmin(ApiKey $apiKey): ?User
    {
        $creator = $apiKey->creator;
        $permissionResolver = app(EffectivePermissionResolverService::class);

        if (
            $creator instanceof User
            && $creator->is_active
            && $permissionResolver->hasPermission($creator, PermissionEnum::CanManageApiKeys->value)
        ) {
            return $creator;
        }

        return $this->instanceAdminPresenceService->firstActiveInstanceAdmin();
    }

    private function findMatchingApiKey(string $plainTextToken): ?ApiKey
    {
        $keyPrefix = $this->apiKeySecretService->keyPrefixOf($plainTextToken);

        if ($keyPrefix === null) {
            return null;
        }

        $apiKey = ApiKey::query()
            ->with(['ownerPrincipal', 'creator'])
            ->where('key_prefix', $keyPrefix)
            ->first();

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
