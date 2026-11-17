<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PushedAuthorizationRequestService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expand an RFC 9126 PAR `request_uri` into the stored authorization parameters.
 *
 * Runs on `/oauth/authorize` before Passport validates the request. Only URIs
 * minted by {@see PushedAuthorizationRequestService} are accepted; other
 * `request_uri` values stay for {@see RejectUnsupportedRequestObjectMiddleware}
 * (`request_uri_not_supported`, #113). Extra authorize parameters are ignored
 * (RFC 9126 §4): the stored push is the sole source of truth.
 */
final class ResolvePushedAuthorizationRequestMiddleware
{
    public function __construct(
        private readonly PushedAuthorizationRequestService $pushedAuthorizationRequestService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('oauth/authorize') || $this->isConsentApproval($request)) {
            return $next($request);
        }

        $requestUri = $request->input('request_uri');

        if (! is_string($requestUri) || $requestUri === '') {
            return $next($request);
        }

        if (! PushedAuthorizationRequestService::isPushedAuthorizationRequestUri($requestUri)) {
            return $next($request);
        }

        // OIDC Core §6.1: `request` still wins and stays unsupported (#113).
        $requestObject = $request->input('request');

        if (is_string($requestObject) && $requestObject !== '') {
            return $next($request);
        }

        $clientId = $request->input('client_id');

        if (! is_string($clientId) || $clientId === '') {
            return $this->invalidRequestResponse();
        }

        $parameters = $this->pushedAuthorizationRequestService->consume($requestUri, $clientId);

        if ($parameters === null) {
            return $this->invalidRequestResponse();
        }

        $this->replaceAuthorizationParameters($request, $parameters);

        return $next($request);
    }

    /**
     * Passport consent approve/deny carries `auth_token`; leave those alone.
     */
    private function isConsentApproval(Request $request): bool
    {
        $authToken = $request->input('auth_token');

        return is_string($authToken) && $authToken !== '';
    }

    /**
     * RFC 9126 §4: ignore authorize-query duplicates; the pushed payload is authoritative.
     *
     * @param  array<string, string>  $parameters
     */
    private function replaceAuthorizationParameters(Request $request, array $parameters): void
    {
        $request->query->replace($parameters);

        foreach (array_keys($request->request->all()) as $name) {
            if ($name === 'auth_token') {
                continue;
            }

            $request->request->remove($name);
        }

        $request->server->set(
            'QUERY_STRING',
            http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
        );
    }

    /**
     * Expired, reused, unknown or client-mismatched PAR URIs (RFC 9126 §4).
     */
    private function invalidRequestResponse(): JsonResponse
    {
        return response()
            ->json(['error' => 'invalid_request'], 400)
            ->header('Cache-Control', 'no-store');
    }
}
