<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse OIDC Request Objects that Signaculum does not implement.
 *
 * Discovery advertises `request_parameter_supported` and
 * `request_uri_parameter_supported` as false (OpenID Connect Discovery 1.0 §3).
 * When a client still sends `request` or `request_uri`, OIDC Core 1.0 §6.1 and
 * §3.1.2.6 require `error=request_not_supported` or
 * `error=request_uri_not_supported` on the registered redirect URI — never a
 * code that ignored the Request Object. `state` and RFC 9207 `iss` are echoed.
 *
 * Registered on the authorization endpoint only (`passport.authorizations.authorize`).
 */
final class RejectUnsupportedRequestObjectMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // GET authorize and form POST authorize (approve route when `response_type` is set).
        if (! $request->is('oauth/authorize') || $this->isConsentApproval($request)) {
            return $next($request);
        }

        $hasRequest = $this->hasNonEmptyParameter($request, 'request');
        $hasRequestUri = $this->hasNonEmptyParameter($request, 'request_uri');

        if (! $hasRequest && ! $hasRequestUri) {
            return $next($request);
        }

        // OIDC Core §6.1: `request` takes precedence when both are present.
        $error = $hasRequest ? 'request_not_supported' : 'request_uri_not_supported';

        return $this->unsupportedRequestRedirect($request, $error);
    }

    /**
     * Passport consent approve/deny carries `auth_token`; leave those alone.
     */
    private function isConsentApproval(Request $request): bool
    {
        $authToken = $request->input('auth_token');

        return is_string($authToken) && $authToken !== '';
    }

    private function hasNonEmptyParameter(Request $request, string $parameter): bool
    {
        $value = $request->input($parameter);

        return is_string($value) && $value !== '';
    }

    /**
     * Redirect to the registered redirect_uri with the unsupported-request error.
     * Unknown client or unregistered URI → HTTP 401 (no open redirect).
     */
    private function unsupportedRequestRedirect(Request $request, string $error): RedirectResponse|Response
    {
        $clientId = $request->input('client_id');
        $redirectUri = $request->input('redirect_uri');

        if (! is_string($clientId) || $clientId === '' || ! is_string($redirectUri) || $redirectUri === '') {
            abort(401);
        }

        $client = Client::query()->find($clientId);

        if ($client === null || ! in_array($redirectUri, $client->redirect_uris, true)) {
            abort(401);
        }

        $query = [
            'error' => $error,
            'iss' => (string) config('oidc.issuer'),
        ];

        $state = $request->input('state');

        if (is_string($state) && $state !== '') {
            $query['state'] = $state;
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return redirect()->away($redirectUri.$separator.http_build_query($query));
    }
}
