<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inertia error pages for web requests (app-ui docs/error-pages.md).
 *
 * Guests hitting a 404 are sent to login so a URL does not reveal what exists.
 * JSON / OIDC clients keep Laravel's default error responses. 500 stays as the
 * framework debug page while APP_DEBUG is on.
 */
final class RenderErrorPageResponse
{
    /** @var list<int> */
    private const STATUSES = [403, 404, 500, 503];

    public static function respond(Response $response, \Throwable $_exception, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! in_array($status, self::STATUSES, true)) {
            return $response;
        }

        if (self::shouldUseDefaultResponse($request)) {
            return $response;
        }

        if ($status === 500 && app()->hasDebugModeEnabled()) {
            return $response;
        }

        // Public branding assets keep a real 404; only HTML "page" 404s hide existence.
        if ($status === 404 && $request->user() === null) {
            if ($request->is('branding/*')) {
                return $response;
            }

            return redirect()->guest(route('login'));
        }

        self::shareInertiaProps($request);

        return Inertia::render('errors/error-page', [
            'status' => $status,
            'homeUrl' => $request->user() !== null
                ? route('dashboard')
                : route('login'),
        ])->toResponse($request)->setStatusCode($status);
    }

    /**
     * Share the HandleInertiaRequests props (auth, sidebar, locale, …) for the error page.
     *
     * Route model binding and unmatched routes can throw before HandleInertiaRequests
     * runs, so without this the page renders without `auth` and AppLayout crashes.
     */
    private static function shareInertiaProps(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        Inertia::share(app(HandleInertiaRequests::class)->share($request));
    }

    private static function shouldUseDefaultResponse(Request $request): bool
    {
        return $request->is('api/*')
            || $request->is('oauth/userinfo')
            || $request->is('.well-known/*')
            || $request->is('health/*')
            || $request->is('oauth/token')
            || $request->expectsJson();
    }
}
