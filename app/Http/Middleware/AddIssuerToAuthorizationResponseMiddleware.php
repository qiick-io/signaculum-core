<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Append RFC 9207 `iss` to every authorization response redirect (success and error)
 * from `/oauth/authorize` (RFC 9207 §2).
 */
final class AddIssuerToAuthorizationResponseMiddleware
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('oauth/authorize') || ! $response->isRedirect()) {
            return $response;
        }

        $location = $response->headers->get('Location');

        if (! is_string($location) || $location === '') {
            return $response;
        }

        $issuer = (string) config('oidc.issuer');

        if ($issuer === '') {
            return $response;
        }

        $parts = parse_url($location);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $response;
        }

        $queryParameters = [];

        if (isset($parts['query'])) {
            parse_str($parts['query'], $queryParameters);
        }

        // Only authorization responses to the client (code or error), never intermediate
        // redirects such as `/login` (RFC 9207 §2).
        if (! isset($queryParameters['code']) && ! isset($queryParameters['error'])) {
            return $response;
        }

        $existingIssuer = $queryParameters['iss'] ?? null;

        if (is_string($existingIssuer) && hash_equals($existingIssuer, $issuer)) {
            return $response;
        }

        $queryParameters['iss'] = $issuer;

        $rebuilt = $parts['scheme'].'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '')
            .'?'.http_build_query($queryParameters)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');

        $response->headers->set('Location', $rebuilt);

        return $response;
    }
}
