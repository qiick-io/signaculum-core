<?php

declare(strict_types=1);

use App\Http\Middleware\SetSecurityHeadersMiddleware;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Run the middleware on a bare HTML response and return its headers.
 */
function securityHeadersForWebPage(): ResponseHeaderBag
{
    return app(SetSecurityHeadersMiddleware::class)
        ->handle(request()->create('/login', 'GET'), fn () => response('<html></html>'))
        ->headers;
}

beforeEach(function (): void {
    Vite::useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
});

test('defense headers are set on web HTML responses', function (): void {
    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Permissions-Policy'))->toContain('microphone=()')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});

test('defense headers are not applied to oidc discovery json', function (): void {
    config(['oidc.issuer' => 'https://id.example.test']);

    $response = $this->getJson('/.well-known/openid-configuration');

    $response->assertOk();
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('X-Frame-Options'))->toBeFalse();
});

test('an existing Content-Security-Policy header is not overwritten', function (): void {
    $response = response('bytes', 200, [
        'Content-Security-Policy' => "default-src 'none'",
    ]);

    $out = app(SetSecurityHeadersMiddleware::class)->handle(
        request()->create('/login', 'GET'),
        fn () => $response,
    );

    expect($out->headers->get('Content-Security-Policy'))->toBe("default-src 'none'")
        ->and($out->headers->get('X-Frame-Options'))->toBe('DENY');
});

test('SECURITY_CSP override is returned verbatim', function (): void {
    config(['security.csp' => "  default-src 'none'  "]);

    expect(securityHeadersForWebPage()->get('Content-Security-Policy'))->toBe("default-src 'none'");
});

test('security headers can be disabled', function (): void {
    config(['security.enabled' => false]);

    expect(securityHeadersForWebPage()->has('X-Frame-Options'))->toBeFalse();
});
