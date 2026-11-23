<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let a client send its access token in a form-encoded POST body (RFC 6750 §2.2).
 *
 * OIDC Core §5.3.1 has the UserInfo endpoint accept both bearer token methods of
 * RFC 6750: the `Authorization` header and the form body. Passport's guard reads only
 * the header, so the body token is moved there before authentication. The body form is
 * honoured only where RFC 6750 allows it: a POST with an
 * `application/x-www-form-urlencoded` body. A client that sends the token both in
 * the header and in the body used more than one method, which RFC 6750 §2 forbids:
 * it gets `invalid_request` (§3.1), as does a body token that is not a single value.
 */
final class AcceptFormBodyAccessTokenMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isFormPost($request) || ! $request->request->has('access_token')) {
            return $next($request);
        }

        // all(): InputBag::get() throws on `access_token[]` before we could answer it.
        $accessToken = $request->request->all()['access_token'];

        if (! is_string($accessToken) || $request->headers->has('Authorization')) {
            return $this->invalidRequest();
        }

        if ($accessToken === '') {
            return $next($request);
        }

        $request->headers->set('Authorization', 'Bearer '.$accessToken);

        return $next($request);
    }

    /**
     * RFC 6750 §3.1: more than one token method, or a malformed token parameter.
     */
    private function invalidRequest(): JsonResponse
    {
        return response()
            ->json(['error' => 'invalid_request'], 400)
            ->header('WWW-Authenticate', 'Bearer error="invalid_request"');
    }

    /**
     * Media types are case-insensitive and may carry parameters (`; charset=UTF-8`).
     */
    private function isFormPost(Request $request): bool
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        return $request->isMethod('POST') && $mediaType === 'application/x-www-form-urlencoded';
    }
}
