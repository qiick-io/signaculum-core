<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\SecondFactorUnavailableException;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuthenticationContextService;
use App\Services\AuthorizationErrorRedirectService;
use App\Services\OidcReauthenticationService;
use App\Services\SecondFactorAvailabilityService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a signed-in user belongs to an organisation that requires MFA, they must have
 * enrolled TOTP or a passkey, and the current session's RFC 8176 `amr` must prove a
 * second factor, before finishing `/oauth/authorize` (#131).
 *
 * Enrolment alone is not enough: email-code-only (`otp`) or password-only (`pwd`)
 * sessions of enrolled users are stepped up like OIDC Core §3.1.2.1 `max_age` /
 * `prompt=login` (local re-auth, same `sid`, no back-channel fan-out). Session
 * satisfaction is `mfa`, or a passkey method (`hwk` / `swk`); `acr` stays truthful
 * and is not invented here.
 *
 * With `prompt=none` (OIDC Core §3.1.2.1 / §3.1.2.6), an unsatisfied organisation MFA
 * requirement returns `interaction_required` to the client's redirect URI with `state`
 * and RFC 9207 `iss`, and leaves the Signaculum session intact (same pattern as
 * {@see EnforceMaximumAuthenticationAgeMiddleware} for a stale `max_age`).
 *
 * The requirement is stored data and never depends on which second-factor features
 * `config/fortify.php` enables (#169): with TOTP off, passkeys still satisfy it, and
 * with every method off the request is refused ({@see SecondFactorUnavailableException},
 * or `access_denied` for `prompt=none`) instead of passing or looping.
 *
 * Instance-wide 2FA (`EnsureTwoFactorIsEnabledMiddleware`) remains separate.
 */
final class EnsureOrganizationMfaIsSatisfiedMiddleware
{
    /**
     * Routes needed to enroll without a redirect loop (same set as instance 2FA).
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTE_NAMES = [
        'logout',
        'security.edit',
        'user-password.update',
        'profile.edit',
        'profile.update',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'two-factor.enable',
        'two-factor.confirm',
        'two-factor.disable',
        'two-factor.qr-code',
        'two-factor.secret-key',
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    public function __construct(
        private readonly AuthenticationContextService $authenticationContextService,
        private readonly OidcReauthenticationService $oidcReauthenticationService,
        private readonly AuthorizationErrorRedirectService $authorizationErrorRedirectService,
        private readonly SecondFactorAvailabilityService $secondFactorAvailabilityService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException when organisation MFA step-up is required
     * @throws SecondFactorUnavailableException when no second-factor method is enabled
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || ! $request->is('oauth/authorize')
            || in_array($request->route()?->getName(), self::EXEMPT_ROUTE_NAMES, true)
            || $this->isConsentApproval($request)
            || ! $this->userBelongsToMfaRequiredOrganization($user)
        ) {
            return $next($request);
        }

        $isPromptNone = $this->isPromptNone($request);

        if (! $this->secondFactorAvailabilityService->hasUsableSecondFactor($user)) {
            if (! $this->secondFactorAvailabilityService->isAnyMethodEnabled()) {
                return $this->refuseUnavailableSecondFactor($request, $isPromptNone);
            }

            if ($isPromptNone) {
                return $this->authorizationErrorRedirectService->redirect($request, 'interaction_required');
            }

            return $this->redirectToEnrolment($request);
        }

        if ($this->authenticationContextService->sessionSatisfiesOrganizationMfa()) {
            return $next($request);
        }

        if ($isPromptNone) {
            return $this->authorizationErrorRedirectService->redirect($request, 'interaction_required');
        }

        $this->startOrganizationMfaStepUp($request);

        throw new AuthenticationException(guards: ['web']);
    }

    /**
     * Passport consent approval is POST with `auth_token` only (no `response_type`).
     */
    private function isConsentApproval(Request $request): bool
    {
        return $request->isMethod('POST') && $request->filled('auth_token') && ! $request->filled('response_type');
    }

    /**
     * OIDC Core §3.1.2.1 `prompt=none`: never show UI; return an error to the client.
     */
    private function isPromptNone(Request $request): bool
    {
        $promptValues = array_values(array_filter(array_map(
            trim(...),
            explode(' ', (string) $request->input('prompt', '')),
        )));

        return in_array('none', $promptValues, true);
    }

    /**
     * No enrolment or step-up can meet the requirement, so neither is offered: the user
     * sees why on a 403 page, and keeps their session for the account area. A silent
     * request gets `access_denied` (RFC 6749 §4.1.2.1), not `interaction_required`,
     * because no interaction can help until the operator enables a method.
     *
     * @throws SecondFactorUnavailableException for an interactive request
     */
    private function refuseUnavailableSecondFactor(Request $request, bool $isPromptNone): Response
    {
        if ($isPromptNone) {
            return $this->authorizationErrorRedirectService->redirect($request, 'access_denied');
        }

        throw new SecondFactorUnavailableException;
    }

    private function userBelongsToMfaRequiredOrganization(User $user): bool
    {
        return Organization::query()
            ->where('is_mfa_required', true)
            ->whereHas('memberships', function ($membershipQuery) use ($user): void {
                $membershipQuery->where('user_id', $user->getKey());
            })
            ->exists();
    }

    private function redirectToEnrolment(Request $request): Response
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('An organisation you belong to requires two-factor authentication. Set up TOTP or a passkey to continue.'),
        ]);

        return redirect()->route('security.edit');
    }

    /**
     * Clear the local web login for a fresh second-factor proof without ending SSO
     * (same pattern as {@see EnforceMaximumAuthenticationAgeMiddleware}).
     *
     * Flashes a toast so the login page explains why email-code alone loops (org MFA).
     */
    private function startOrganizationMfaStepUp(Request $request): void
    {
        $this->oidcReauthenticationService->begin();
        $preservedSid = $this->oidcReauthenticationService->preservedOidcSessionId();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->oidcReauthenticationService->restoreAfterSessionRotation($preservedSid);

        $request->session()->put('url.intended', $this->authorizationRequestUrl($request));

        Inertia::flash('toast', [
            'type' => 'error',
            'message' => __('An organisation you belong to requires a second factor. Sign in with your authenticator or a passkey.'),
        ]);
    }

    /**
     * Intended URL after step-up: always a GET authorize URL so a later browser
     * redirect does not depend on a replayed form POST body.
     */
    private function authorizationRequestUrl(Request $request): string
    {
        if ($request->isMethod('GET')) {
            return $request->fullUrl();
        }

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
}
