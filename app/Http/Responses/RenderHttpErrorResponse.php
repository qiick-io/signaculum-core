<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Web 403 / 404 / 500 / 503 as one Inertia error page (app-ui `error-pages.md`).
 *
 * Guests on a 404 are sent to login so a missing URL does not reveal what exists.
 * JSON / OIDC clients keep Laravel's default payload.
 */
final class RenderHttpErrorResponse
{
    /**
     * @var list<int>
     */
    private const RENDERABLE_STATUSES = [403, 404, 500, 503];

    public static function render(HttpExceptionInterface $exception, Request $request): ?Response
    {
        $status = $exception->getStatusCode();

        if (! in_array($status, self::RENDERABLE_STATUSES, true)) {
            return null;
        }

        if (self::shouldUseDefaultResponse($request)) {
            return null;
        }

        if ($status === 500 && app()->hasDebugModeEnabled()) {
            return null;
        }

        if ($status === 404 && $request->user() === null) {
            // Public branding assets (login logos) must return a real 404, not a login redirect.
            if ($request->routeIs('branding.show', 'organizations.branding.show')) {
                return null;
            }

            return redirect()->guest(route('login'));
        }

        self::shareInertiaProps($request);

        $homeUrl = $request->user() !== null
            ? route('dashboard')
            : route('login');

        return Inertia::render('errors/error-page', [
            'status' => $status,
            'homeUrl' => $homeUrl,
        ])->toResponse($request)->setStatusCode($status);
    }

    /**
     * Share HandleInertiaRequests props so AppLayout has auth after a model-binding 404.
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
            || $request->is('.well-known/*')
            || $request->is('oauth/userinfo')
            || $request->expectsJson();
    }
}
