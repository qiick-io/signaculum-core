<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\BearerTokenErrorResponse;
use App\Models\ApiKey;
use App\Services\ApiKeyAuthorizerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Laravel\AccessContext;
use Qiick\ApiKeys\ApiKeyAuthenticator;
use Qiick\ApiKeys\Exceptions\ApiKeyRejection;
use Qiick\ApiKeys\Http\BearerTokenExtractor;
use Qiick\ApiKeys\Http\KeyContextFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Admin REST API requests with a Bearer API key, failing closed, through
 * qiick/laravel-api-keys' {@see ApiKeyAuthenticator}: failed-attempt brake, format, lookup and
 * constant-time hash match, revoked and expired keys, the owner rule
 * ({@see ApiKeyAuthorizerService}), IP allowlist, scopes, rate limits, then usage. Every refusal
 * keeps the package's RFC 6750 shape (`error`, `message`, `WWW-Authenticate`).
 *
 * Keys are owned by the singleton `instance_service` principal. They remain valid while that
 * principal is active and at least one active instance admin still exists; demoting the creating
 * admin alone does not revoke them (decision 2026-11-22 / #229).
 *
 * Side effects: authenticates the acting admin on the separate `api-key` guard (never the
 * session guard), puts the key on the request as `apiKey`, records `last_used_*` (throttled by
 * the package), counts the key's rate limits and failed guesses per client address, and applies
 * the actor's locale when no supported `Accept-Language` was requested, and binds the key as the
 * qiick/laravel-access API actor (AccessContext).
 */
final class AuthenticateApiKeyMiddleware
{
    public function __construct(
        private readonly ApiKeyAuthenticator $apiKeyAuthenticator,
        private readonly ApiKeyAuthorizerService $apiKeyAuthorizerService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $presentedToken = (new BearerTokenExtractor)->fromRequest($request);

        if ($presentedToken === null) {
            return BearerTokenErrorResponse::invalidToken(__('Missing API key.'));
        }

        try {
            $authentication = $this->apiKeyAuthenticator->authenticate($presentedToken, KeyContextFactory::fromRequest($request));
        } catch (ApiKeyRejection $rejection) {
            return $rejection->render();
        }

        /** @var ApiKey $apiKey */
        $apiKey = $authentication->record;
        // authorizeActor() already refused a key nobody can act as.
        $actingAdmin = $this->apiKeyAuthorizerService->actingAdminFor($apiKey);

        if ($actingAdmin === null) {
            return BearerTokenErrorResponse::invalidToken(__('API key owner may not use the API.'));
        }

        $request->attributes->set('apiKey', $apiKey);
        Auth::guard('api-key')->setUser($actingAdmin);
        Auth::shouldUse('api-key');
        // The verified actor for qiick/laravel-access: an API actor (no super-admin authority),
        // carried by the jobs this request queues.
        AccessContext::enter(new ActorContext(ActorKind::ApiKey, $actingAdmin, $apiKey->uuid));

        // The global locale middleware ran before key auth; apply the actor's locale here.
        SetLocaleMiddleware::applyPrincipalLocale($request, is_string($actingAdmin->locale) ? $actingAdmin->locale : null);

        return $next($request);
    }
}
