<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the authenticated API key to carry one of the given scopes.
 */
final class EnsureApiKeyScopeMiddleware
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $apiKey = $request->attributes->get('apiKey');

        if (! $apiKey instanceof ApiKey) {
            abort(401, __('Missing API key.'));
        }

        foreach ($scopes as $scope) {
            if ($apiKey->hasScope($scope)) {
                return $next($request);
            }
        }

        abort(403, __('API key is missing the required scope.'));
    }
}
