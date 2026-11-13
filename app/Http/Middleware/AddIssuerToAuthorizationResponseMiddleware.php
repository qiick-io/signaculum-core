<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appends RFC 9207 `iss` to OAuth authorization redirects back to the client.
 *
 * Mix-up attacks trick a multi-IdP client into sending a code to the wrong
 * token endpoint. Putting the issuer on every success and error response lets
 * the client reject a response that did not come from the AS it started with
 * (RFC 9207 §2). Discovery advertises the same value via
 * `authorization_response_iss_parameter_supported` (RFC 9207 §3).
 *
 * Registered only on `passport.authorizations.authorize|approve|deny` so other
 * `web` redirects that happen to carry `code` or `error` are left alone.
 */
final class AddIssuerToAuthorizationResponseMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->isRedirect()) {
            return $response;
        }

        $location = $response->headers->get('Location');

        if (! is_string($location) || $location === '') {
            return $response;
        }

        $locationWithIssuer = $this->locationWithIssuer(
            $location,
            (string) config('oidc.issuer'),
        );

        if ($locationWithIssuer !== $location) {
            $response->headers->set('Location', $locationWithIssuer);
        }

        return $response;
    }

    /**
     * Add `iss` when the Location is an authorization response to the client
     * (query carries `code` or `error`). Internal redirects such as `/login`
     * are left unchanged (RFC 9207 §2).
     */
    private function locationWithIssuer(string $location, string $issuer): string
    {
        $parts = parse_url($location);

        if ($parts === false || ! isset($parts['query'])) {
            return $location;
        }

        parse_str($parts['query'], $query);

        if (! array_key_exists('code', $query) && ! array_key_exists('error', $query)) {
            return $location;
        }

        $query['iss'] = $issuer;

        return $this->rebuildLocation($parts, http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param  array<string, int|string>  $parts
     */
    private function rebuildLocation(array $parts, string $query): string
    {
        $location = '';

        if (isset($parts['scheme'])) {
            $location .= $parts['scheme'].'://';
        }

        if (isset($parts['user'])) {
            $location .= $parts['user'];

            if (isset($parts['pass'])) {
                $location .= ':'.$parts['pass'];
            }

            $location .= '@';
        }

        if (isset($parts['host'])) {
            $location .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $location .= ':'.$parts['port'];
        }

        $location .= $parts['path'] ?? '';
        $location .= '?'.$query;

        if (isset($parts['fragment'])) {
            $location .= '#'.$parts['fragment'];
        }

        return $location;
    }
}
