<?php

use App\Enums\ApiErrorCodeEnum;
use App\Http\Middleware\CaptureOrganizationBrandingMiddleware;
use App\Http\Middleware\EnsureOrganizationMfaIsSatisfiedMiddleware;
use App\Http\Middleware\EnsureTwoFactorIsEnabledMiddleware;
use App\Http\Middleware\EnsureUserHasPermissionMiddleware;
use App\Http\Middleware\EnsureUserIsActiveMiddleware;
use App\Http\Middleware\ExpireImpersonationMiddleware;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocaleMiddleware;
use App\Http\Middleware\SetSecurityHeadersMiddleware;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Http\Responses\RenderHttpErrorResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
        then: function (): void {
            Route::middleware('api')->group(base_path('routes/oidc.php'));

            require base_path('routes/health.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnsureUserHasPermissionMiddleware::class,
        ]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale', 'accessibility_high_contrast', 'accessibility_reduce_motion']);

        // RPs POST to end-session without a Signaculum CSRF cookie (OIDC RP-Initiated Logout §2).
        // Authorization-request POST exemption lives in VerifyCsrfTokenMiddleware (not a path
        // blanket: consent approve on the same URI must keep CSRF).
        $middleware->validateCsrfTokens(except: [
            'oauth/end-session',
        ]);

        $middleware->web(replace: [
            PreventRequestForgery::class => VerifyCsrfTokenMiddleware::class,
        ], append: [
            CaptureOrganizationBrandingMiddleware::class,
            // Before EnsureUserIsActive so an expired target restores the actor first.
            ExpireImpersonationMiddleware::class,
            EnsureUserIsActiveMiddleware::class,
            SetLocaleMiddleware::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            EnsureTwoFactorIsEnabledMiddleware::class,
            EnsureOrganizationMfaIsSatisfiedMiddleware::class,
            // Last so defense headers apply to the final HTML (app-ui security-headers.md).
            SetSecurityHeadersMiddleware::class,
        ]);

        // Locale and Content-Language on the machine doors too, including their error responses.
        $middleware->api(append: [SetLocaleMiddleware::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isMachineDoor = static fn (Request $request): bool => $request->is('api/*', 'mcp', 'mcp/*');
        $isJsonDoor = static fn (Request $request): bool => $isMachineDoor($request)
            || $request->is('oauth/userinfo', 'oauth/introspect', 'oauth/revoke', 'oauth/par', '.well-known/*');

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $isJsonDoor($request) || $request->expectsJson(),
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

        /*
         * Fleet API error code standard: one mapping, one render hook. Laravel renders its own
         * JSON (message, field errors, rate-limit headers); the final-response callback below
         * only adds `error`, and leaves an existing RFC 6750 `error` key untouched.
         */
        $exceptions->render(function (Throwable $exception, Request $request) use ($exceptions, $isMachineDoor): null {
            if (! $isMachineDoor($request)) {
                return null;
            }

            $errorCode = ApiErrorCodeEnum::forException($exception);

            $exceptions->respond(static function (Response $response, Throwable $thrown, Request $currentRequest) use ($errorCode, $isMachineDoor): Response {
                if (! $response instanceof JsonResponse
                    || $response->getStatusCode() < 400
                    || ! $isMachineDoor($currentRequest)) {
                    return $response;
                }

                $payload = $response->getData(true);

                if (! is_array($payload) || array_key_exists('error', $payload)) {
                    return $response;
                }

                return $response->setData([...$payload, 'error' => $errorCode->value]);
            });

            return null;
        });
    })->create();
