<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\BearerTokenErrorResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the authenticated API key to carry one of the given scopes; `write` satisfies `read`
 * on the same resource. A route with no scope listed refuses every key.
 *
 * Declared divergence from the app-ui baseline: as the identity provider, Signaculum gates its
 * Admin API endpoints with this route middleware rather than policies alone.
 */
final class EnsureApiKeyScopeMiddleware
{
    public function handle(Request $request, Closure $next, string ...$requiredScopes): Response
    {
        $apiKey = $request->attributes->get('apiKey');

        if (! $apiKey instanceof ApiKey) {
            return BearerTokenErrorResponse::invalidToken(__('Missing API key.'));
        }

        foreach ($requiredScopes as $requiredScope) {
            if ($apiKey->hasScope($requiredScope)) {
                return $next($request);
            }
        }

        return BearerTokenErrorResponse::insufficientScope(
            __('API key is missing the required scope.'),
            array_values($requiredScopes),
        );
    }
}
