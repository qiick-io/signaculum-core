<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Let a client send its access token in a form-encoded POST body (RFC 6750 §2.2).
 *
 * OIDC Core §5.3.1 has the UserInfo endpoint accept both bearer token methods of
 * RFC 6750: the `Authorization` header and the form body. Passport's guard reads only
 * the header, so the body token is moved there before authentication. The body form is
 * honoured only where RFC 6750 allows it: a POST with an
 * `application/x-www-form-urlencoded` body and no `Authorization` header.
 */
final class AcceptFormBodyAccessTokenMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accessToken = $request->request->get('access_token');
        $isFormPost = $request->isMethod('POST')
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/x-www-form-urlencoded');

        if ($isFormPost && is_string($accessToken) && $accessToken !== '' && ! $request->headers->has('Authorization')) {
            $request->headers->set('Authorization', 'Bearer '.$accessToken);
        }

        return $next($request);
    }
}
