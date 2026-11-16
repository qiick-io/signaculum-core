<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Client;
use App\Services\OidcReauthenticationService;
use App\Services\PendingAuthorizationService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honour OIDC Core §3.1.2.1 `max_age` on the authorization endpoint, and make
 * Passport's `prompt=login` re-authenticate without ending SSO sessions.
 *
 * Re-authentication clears the local web login only (flag
 * {@see OidcReauthenticationService}): no back-channel fan-out, same `sid`.
 * With `prompt=none` and a too-old `auth_time`, the client gets `login_required`
 * while the Signaculum session stays intact.
 */
final class EnforceMaximumAuthenticationAgeMiddleware
{
    public function __construct(
        private readonly PendingAuthorizationService $pendingAuthorizationService,
        private readonly OidcReauthenticationService $oidcReauthenticationService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException when interactive re-authentication is required
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAuthorizationEndpointEntry($request)) {
            return $next($request);
        }

        $promptValues = $this->promptValues($request);
        $isPromptNone = in_array('none', $promptValues, true);
        $webGuard = Auth::guard('web');

        if (
            ! $isPromptNone
            && in_array('login', $promptValues, true)
            && ! $webGuard->guest()
            && ! $request->session()->get('promptedForLogin', false)
        ) {
            $this->startInteractiveReauthentication($request);

            throw new AuthenticationException(guards: ['web']);
        }

        $maxAgeParameter = $request->input('max_age');

        if (! is_string($maxAgeParameter) || $maxAgeParameter === '' || ! ctype_digit($maxAgeParameter)) {
            return $next($request);
        }

        if ($webGuard->guest()) {
            return $next($request);
        }

        $authenticationTime = $this->pendingAuthorizationService->authenticationTime();
        $elapsedSeconds = $authenticationTime === null
            ? PHP_INT_MAX
            : Date::now()->getTimestamp() - $authenticationTime;

        if ($elapsedSeconds <= (int) $maxAgeParameter) {
            // OIDC Core §3.1.2.1: when max_age was used, the id_token must carry auth_time.
            $this->pendingAuthorizationService->rememberMaxAgeRequested();

            return $next($request);
        }

        if ($isPromptNone) {
            return $this->loginRequiredRedirect($request);
        }

        $this->startInteractiveReauthentication($request);

        throw new AuthenticationException(guards: ['web']);
    }

    /**
     * Authorization request entry (GET or form POST), not consent approve/deny.
     *
     * Consent POST carries `auth_token` and must not re-run max_age / prompt=login.
     */
    private function isAuthorizationEndpointEntry(Request $request): bool
    {
        if ($request->routeIs('passport.authorizations.authorize') && $request->isMethod('GET')) {
            return true;
        }

        if (! $request->routeIs('passport.authorizations.approve') || ! $request->isMethod('POST')) {
            return false;
        }

        $authToken = $request->input('auth_token');

        if (is_string($authToken) && $authToken !== '') {
            return false;
        }

        $responseType = $request->input('response_type');

        return is_string($responseType) && $responseType !== '';
    }

    /**
     * @return list<string>
     */
    private function promptValues(Request $request): array
    {
        return array_values(array_filter(array_map(
            trim(...),
            explode(' ', (string) $request->input('prompt', '')),
        )));
    }

    /**
     * Clear the local web login for a fresh credential check without ending SSO.
     */
    private function startInteractiveReauthentication(Request $request): void
    {
        $this->oidcReauthenticationService->begin();
        $preservedSid = $this->oidcReauthenticationService->preservedOidcSessionId();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->oidcReauthenticationService->restoreAfterSessionRotation($preservedSid);

        // So Passport does not run its own logout again after the user returns.
        $request->session()->put('promptedForLogin', true);
        $request->session()->put('url.intended', $this->authorizationRequestUrl($request));
    }

    /**
     * Intended URL after re-authentication: always a GET authorize URL so a later
     * browser redirect does not depend on a replayed form POST body.
     */
    private function authorizationRequestUrl(Request $request): string
    {
        $parameters = array_filter(
            $request->input(),
            fn (mixed $value, string|int $key): bool => is_string($key)
                && $key !== '_token'
                && $key !== 'auth_token'
                && (is_string($value) || is_numeric($value)),
            ARRAY_FILTER_USE_BOTH,
        );

        return url('/oauth/authorize').'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Silent failure for `prompt=none` when re-authentication would be required
     * (OIDC Core §3.1.2.1 / §3.1.2.6): keep the browser session, redirect with
     * `login_required`, `state`, and `iss` (RFC 9207).
     */
    private function loginRequiredRedirect(Request $request): RedirectResponse|Response
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
            'error' => 'login_required',
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
