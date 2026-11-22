<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use Illuminate\Http\Request;

/**
 * Feature tests skip CSRF via PreventRequestForgery::runningUnitTests(), so the
 * OIDC authorization-request exemption is asserted here directly.
 */
test('form post authorization requests are exempt from csrf', function () {
    $middleware = app(VerifyCsrfTokenMiddleware::class);
    $request = Request::create('/oauth/authorize', 'POST', [
        'response_type' => 'code',
        'client_id' => 'example-client',
    ]);

    expect($middleware->isOidcAuthorizationRequestPost($request))->toBeTrue();
});

test('consent approval posts are not exempt from csrf', function () {
    $middleware = app(VerifyCsrfTokenMiddleware::class);
    $request = Request::create('/oauth/authorize', 'POST', [
        'auth_token' => 'consent-token',
    ]);

    expect($middleware->isOidcAuthorizationRequestPost($request))->toBeFalse();
});

test('get authorize is not treated as an exempt post', function () {
    $middleware = app(VerifyCsrfTokenMiddleware::class);
    $request = Request::create('/oauth/authorize', 'GET', [
        'response_type' => 'code',
    ]);

    expect($middleware->isOidcAuthorizationRequestPost($request))->toBeFalse();
});
