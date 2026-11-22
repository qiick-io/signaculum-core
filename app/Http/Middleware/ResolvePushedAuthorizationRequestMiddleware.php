<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Client;
use App\Services\AuthorizationErrorRedirectService;
use App\Services\PushedAuthorizationRequestService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Expand an RFC 9126 PAR `request_uri` into the stored authorization parameters,
 * and refuse plain authorization requests from clients that require PAR.
 *
 * Runs on `/oauth/authorize` before Passport validates the request and before
 * {@see EnforceMaximumAuthenticationAgeMiddleware}, so `prompt` / `max_age` from
 * the push are honoured. Only URIs minted by {@see PushedAuthorizationRequestService}
 * are accepted; other `request_uri` values stay for
 * {@see RejectUnsupportedRequestObjectMiddleware} (`request_uri_not_supported`, #113).
 * Extra authorize parameters are ignored (RFC 9126 §4): the push is authoritative.
 *
 * The URI is spent on first use (RFC 9126 §4 one-time use). When that first use
 * sends the browser to log in, the expanded request is kept in the session under
 * the URI and the intended URL names the URI, not the parameters: the pushed
 * parameters never travel through the browser, and only the same session can
 * finish the request it started.
 */
final class ResolvePushedAuthorizationRequestMiddleware
{
    /**
     * Session map of pushed requests waiting for the user to log in, keyed by `request_uri`.
     */
    private const PENDING_SESSION_KEY = 'oidc.pending_pushed_authorization_requests';

    public function __construct(
        private readonly PushedAuthorizationRequestService $pushedAuthorizationRequestService,
        private readonly AuthorizationErrorRedirectService $authorizationErrorRedirectService,
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
        $requestObject = $request->input('request');

        // OIDC Core §6.1: `request` still wins and stays unsupported (#113).
        $isPushedRequest = is_string($requestUri)
            && PushedAuthorizationRequestService::isPushedAuthorizationRequestUri($requestUri)
            && ! (is_string($requestObject) && $requestObject !== '');

        if (! $isPushedRequest) {
            return $this->refuseWhenPushRequired($request) ?? $next($request);
        }

        $clientId = $request->input('client_id');

        if (! is_string($clientId) || $clientId === '') {
            return $this->invalidRequestResponse();
        }

        $parameters = $this->pendingParameters($request, $requestUri, $clientId)
            ?? $this->pushedAuthorizationRequestService->consume($requestUri, $clientId);

        if ($parameters === null) {
            return $this->invalidRequestResponse();
        }

        $pushedRequestUrl = url('/oauth/authorize').'?'.http_build_query([
            'client_id' => $clientId,
            'request_uri' => $requestUri,
        ], '', '&', PHP_QUERY_RFC3986);

        $this->replaceAuthorizationParameters($request, $parameters, $pushedRequestUrl);

        $response = $next($request);

        $this->rememberWhileLoggingIn($request, $response, $requestUri, $clientId, $parameters, $pushedRequestUrl);

        return $response;
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
     * RFC 9126 §6 `require_pushed_authorization_requests`: a client with the flag
     * may start authorization only through the PAR endpoint.
     *
     * RFC 9126 defines no dedicated error, so this answers `invalid_request` (RFC 6749
     * §4.1.2.1: the request is missing a required parameter, `request_uri`) on the
     * registered redirect URI with `state` and `iss`. An unregistered redirect URI
     * gets HTTP 401 and no redirect, as for every other authorization error.
     */
    private function refuseWhenPushRequired(Request $request): ?Response
    {
        $clientId = $request->input('client_id');

        if (! is_string($clientId) || $clientId === '') {
            return null;
        }

        $client = Client::query()->find($clientId);

        if (! $client instanceof Client || ! $client->requiresPushedAuthorizationRequests()) {
            return null;
        }

        return $this->authorizationErrorRedirectService->redirect($request, 'invalid_request');
    }

    /**
     * The expanded request this session kept for `$requestUri` while the user logged in.
     *
     * Taken out of the session on read: {@see rememberWhileLoggingIn()} puts it back
     * only if this request again ends at the login page.
     *
     * @return array<string, string>|null
     */
    private function pendingParameters(Request $request, string $requestUri, string $clientId): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        /** @var array<string, array{client_id: string, parameters: array<string, string>}> $pendingRequests */
        $pendingRequests = $request->session()->get(self::PENDING_SESSION_KEY, []);
        $pendingRequest = $pendingRequests[$requestUri] ?? null;

        unset($pendingRequests[$requestUri]);
        $request->session()->put(self::PENDING_SESSION_KEY, $pendingRequests);

        if ($pendingRequest === null || $pendingRequest['client_id'] !== $clientId) {
            return null;
        }

        return $pendingRequest['parameters'];
    }

    /**
     * Keep a spent pushed request for this session only while its browser is sent to
     * log in (guest, `prompt=login`, `max_age`), and point the intended URL at the
     * `request_uri` instead of the expanded parameters.
     *
     * @param  array<string, string>  $parameters
     */
    private function rememberWhileLoggingIn(
        Request $request,
        Response $response,
        string $requestUri,
        string $clientId,
        array $parameters,
        string $pushedRequestUrl,
    ): void {
        if (! $request->hasSession() || ! Auth::guard('web')->guest() || ! $this->isLoginRedirect($response)) {
            return;
        }

        $session = $request->session();

        /** @var array<string, array{client_id: string, parameters: array<string, string>}> $pendingRequests */
        $pendingRequests = $session->get(self::PENDING_SESSION_KEY, []);
        $pendingRequests[$requestUri] = ['client_id' => $clientId, 'parameters' => $parameters];

        $session->put(self::PENDING_SESSION_KEY, $pendingRequests);
        $session->put('url.intended', $pushedRequestUrl);
    }

    private function isLoginRedirect(Response $response): bool
    {
        return $response->isRedirect(route('login'));
    }

    /**
     * RFC 9126 §4: ignore authorize-query duplicates; the pushed payload is authoritative.
     *
     * The query string keeps naming the `request_uri`, so a login redirect built from
     * the URL never copies the pushed parameters into the browser.
     *
     * @param  array<string, string>  $parameters
     */
    private function replaceAuthorizationParameters(Request $request, array $parameters, string $pushedRequestUrl): void
    {
        $request->query->replace($parameters);

        foreach (array_keys($request->request->all()) as $name) {
            if ($name === 'auth_token') {
                continue;
            }

            $request->request->remove($name);
        }

        $request->server->set('QUERY_STRING', (string) parse_url($pushedRequestUrl, PHP_URL_QUERY));
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
