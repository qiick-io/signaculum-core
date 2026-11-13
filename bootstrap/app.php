<?php

use App\Http\Middleware\EnsureTwoFactorIsEnabledMiddleware;
use App\Http\Middleware\EnsureUserIsActiveMiddleware;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Middleware\SetSecurityHeadersMiddleware;
use App\Http\Responses\RenderHttpErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/oidc.php'));

            require base_path('routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale', 'accessibility_high_contrast', 'accessibility_reduce_motion']);

        // RPs POST to end-session without a Signaculum CSRF cookie (OIDC RP-Initiated Logout §2).
        $middleware->validateCsrfTokens(except: [
            'oauth/end-session',
        ]);

        $middleware->web(append: [
            EnsureUserIsActiveMiddleware::class,
            SetLocaleMiddleware::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureTwoFactorIsEnabledMiddleware::class,
            // Last so defense headers apply to the final HTML (app-ui security-headers.md).
            SetSecurityHeadersMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'oauth/userinfo', '.well-known/*') || $request->expectsJson(),
        );

        // RFC 6750 §3: a protected resource answers a missing or bad token with a challenge.
        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('oauth/userinfo')) {
                return null;
            }

            return response()
                ->json(['error' => 'invalid_token'], 401)
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            return RenderHttpErrorResponse::render($exception, $request);
        });
    })->create();
