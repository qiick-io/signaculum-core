<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Admin REST API requests with a Bearer API key.
 *
 * Side effects: binds the key owner as the request user (for policies), updates
 * `last_used_at`, and enforces the per-key rate limit.
 */
final class AuthenticateApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer === null || $bearer === '') {
            abort(401, __('Missing API key.'));
        }

        $apiKey = ApiKey::query()
            ->with('owner')
            ->where('key_hash', ApiKey::hashSecret($bearer))
            ->first();

        if ($apiKey === null || ! $apiKey->isActive()) {
            abort(401, __('Invalid or inactive API key.'));
        }

        $owner = $apiKey->owner;

        if ($owner === null || ! $owner->is_active || ! $owner->is_instance_admin) {
            abort(401, __('Invalid or inactive API key.'));
        }

        if (! $apiKey->allowsIp((string) $request->ip())) {
            abort(401, __('API key is not allowed from this IP address.'));
        }

        $limit = $apiKey->rate_limit_per_minute
            ?? (int) config('api.default_rate_limit_per_minute', 60);

        if ($limit > 0) {
            $rateKey = 'api-key:'.$apiKey->getKey();

            if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
                abort(429, __('API key rate limit exceeded.'));
            }

            RateLimiter::hit($rateKey, 60);
        }

        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        Auth::setUser($owner);
        $request->attributes->set('apiKey', $apiKey);

        return $next($request);
    }
}
