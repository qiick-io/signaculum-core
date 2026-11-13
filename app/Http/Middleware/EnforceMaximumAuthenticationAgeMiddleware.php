<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PendingAuthorizationService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Honour OIDC Core §3.1.2.1 `max_age` on the authorization endpoint.
 *
 * When the elapsed time since `auth_time` exceeds `max_age` (or `auth_time` is
 * unknown), the end-user must re-authenticate. With `prompt=none` Passport then
 * returns `login_required` to the client; otherwise the user is sent to login.
 * Passport already covers `prompt=none|login|consent` — this middleware only
 * adds what it lacks for `max_age`.
 */
final class EnforceMaximumAuthenticationAgeMiddleware
{
    public function __construct(
        private readonly PendingAuthorizationService $pendingAuthorizationService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthenticationException when re-authentication is required without `prompt=none`
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('passport.authorizations.authorize') || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $maxAgeParameter = $request->query('max_age');

        if (! is_string($maxAgeParameter) || $maxAgeParameter === '' || ! ctype_digit($maxAgeParameter)) {
            return $next($request);
        }

        $webGuard = Auth::guard('web');

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

        $webGuard->logout();

        $promptValues = array_values(array_filter(array_map(
            trim(...),
            explode(' ', (string) $request->query('prompt', '')),
        )));

        if (in_array('none', $promptValues, true)) {
            return $next($request);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $request->session()->put('url.intended', $request->fullUrl());

        throw new AuthenticationException(guards: ['web']);
    }
}
