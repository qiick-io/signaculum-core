<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\SecondFactorUnavailableException;
use App\Services\SecondFactorAvailabilityService;
use App\Services\SettingsRepositoryService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * When the settings require 2FA, sends users without it to their security page.
 * A confirmed TOTP or at least one passkey satisfies the requirement, as in externa-core.
 * Copied from Excubia. A GET (for example an OAuth authorization request from an app) is
 * kept as the intended URL, so the login can continue after enrolment.
 *
 * The requirement is a stored setting and holds whatever second-factor features
 * `config/fortify.php` enables (#169). Only a factor this instance can challenge counts
 * ({@see SecondFactorAvailabilityService}); with no method enabled at all, the user is
 * refused with an explanation, since the security page would have nothing to offer.
 */
final class EnsureTwoFactorIsEnabledMiddleware
{
    /**
     * Routes needed to enroll (or leave) without a redirect loop.
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
        private SettingsRepositoryService $settings,
        private SecondFactorAvailabilityService $secondFactorAvailabilityService,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws SecondFactorUnavailableException when no second-factor method is enabled
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || in_array($request->route()?->getName(), self::EXEMPT_ROUTE_NAMES, true)
            || ! $this->settings->isTwoFactorRequired()
            || $this->secondFactorAvailabilityService->hasUsableSecondFactor($user)
        ) {
            return $next($request);
        }

        if (! $this->secondFactorAvailabilityService->isAnyMethodEnabled()) {
            throw new SecondFactorUnavailableException;
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => __('Set up two-factor authentication or a passkey to continue.')]);

        return redirect()->route('security.edit');
    }
}
