<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Qiick\ApiKeys\Exceptions\ApiKeyRejection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Admin API's per-address throttle, in front of key authentication: every request from one
 * client address counts, whatever key it carries. It has its own setting
 * (`api.address_rate_limit_per_minute`, 0 switches it off) so the per-key limits of
 * qiick/laravel-api-keys keep their own meaning, and it answers in the package's shapes: 429
 * `rate_limit_exceeded`, and 503 `service_unavailable` when the rate-limit store is down.
 *
 * Side effects: counts the request on the default cache store (Laravel's rate limiter).
 */
final class ThrottleApiAddressMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $maximumAttempts = config()->integer('api.address_rate_limit_per_minute');

        if ($maximumAttempts <= 0) {
            return $next($request);
        }

        $bucketKey = 'api-address:'.sha1((string) $request->ip());

        try {
            if (RateLimiter::tooManyAttempts($bucketKey, $maximumAttempts)) {
                return ApiKeyRejection::rateLimited(RateLimiter::availableIn($bucketKey), $maximumAttempts, remaining: 0)->render();
            }

            RateLimiter::hit($bucketKey);
        } catch (\Throwable $exception) {
            // An unreachable store fails closed like the package does, never as a bare 500.
            report($exception);

            return ApiKeyRejection::serviceUnavailable()->render();
        }

        return $next($request);
    }
}
