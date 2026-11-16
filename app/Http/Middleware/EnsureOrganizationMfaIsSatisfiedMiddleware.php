<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a signed-in user belongs to an organisation that requires MFA, they must have
 * enrolled TOTP or a passkey before finishing `/oauth/authorize`. Instance-wide 2FA
 * (`EnsureTwoFactorIsEnabledMiddleware`) is separate; this gate is per organisation (#120).
 *
 * Does not change `amr` / `acr`: those stay truthful to how the user authenticated.
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

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || ! $request->is('oauth/authorize')
            || in_array($request->route()?->getName(), self::EXEMPT_ROUTE_NAMES, true)
            || $this->isConsentApproval($request)
            || ! Features::canManageTwoFactorAuthentication()
            || $this->userSatisfiesMfa($user)
            || ! $this->userBelongsToMfaRequiredOrganization($user)
        ) {
            return $next($request);
        }

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
     * Passport consent approval is POST with `auth_token` only (no `response_type`).
     */
    private function isConsentApproval(Request $request): bool
    {
        return $request->isMethod('POST') && $request->filled('auth_token') && ! $request->filled('response_type');
    }

    private function userSatisfiesMfa(User $user): bool
    {
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        return Features::canManagePasskeys() && $user->hasPasskeysEnabled();
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
}
