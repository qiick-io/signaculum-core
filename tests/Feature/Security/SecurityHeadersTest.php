<?php

declare(strict_types=1);

use App\Http\Middleware\SetSecurityHeadersMiddleware;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Run the middleware on a bare web HTML response and return its headers.
 */
function securityHeadersForWebPage(): ResponseHeaderBag
{
    return app(SetSecurityHeadersMiddleware::class)
        ->handle(request()->create('/dashboard', 'GET'), fn () => response('<html></html>'))
        ->headers;
}

/**
 * Extract one directive (e.g. `media-src ...`) from a Content-Security-Policy header.
 */
function contentSecurityPolicyDirective(ResponseHeaderBag $headers, string $directiveName): string
{
    foreach (explode(';', (string) $headers->get('Content-Security-Policy')) as $directive) {
        if (str_starts_with(trim($directive), $directiveName.' ')) {
            return trim($directive);
        }
    }

    return '';
}

beforeEach(function (): void {
    // Never pick up a developer's real public/hot file.
    Vite::useHotFile(storage_path('framework/testing/missing-vite-hot-file'));
});

it('sets defense headers on web HTML responses', function (): void {
    $this->withoutVite();

    $response = $this->get('/login');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
});

it('does not apply admin CSP to OIDC JSON endpoints', function (): void {
    $response = $this->getJson('/.well-known/openid-configuration');

    $response->assertOk();
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('does not overwrite an existing Content-Security-Policy header', function (): void {
    $existingPolicy = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
    $response = response('branding-bytes', 200, [
        'Content-Security-Policy' => $existingPolicy,
        'X-Content-Type-Options' => 'nosniff',
    ]);

    $middleware = app(SetSecurityHeadersMiddleware::class);
    $out = $middleware->handle(
        request()->create('/branding/logo', 'GET'),
        fn () => $response,
    );

    expect($out->headers->get('Content-Security-Policy'))->toBe($existingPolicy)
        ->and($out->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('builds the default Content-Security-Policy without a remote files origin', function (): void {
    $headers = securityHeadersForWebPage();

    expect(contentSecurityPolicyDirective($headers, 'media-src'))->toBe("media-src 'self' blob:")
        ->and(contentSecurityPolicyDirective($headers, 'img-src'))->toBe("img-src 'self' data: blob: https:")
        ->and(contentSecurityPolicyDirective($headers, 'script-src'))->toBe("script-src 'self' 'unsafe-inline' 'unsafe-eval'")
        ->and(contentSecurityPolicyDirective($headers, 'font-src'))->toBe("font-src 'self' data:")
        ->and(contentSecurityPolicyDirective($headers, 'connect-src'))->toBe("connect-src 'self' ws: wss: http: https:")
        ->and(contentSecurityPolicyDirective($headers, 'frame-ancestors'))->toBe("frame-ancestors 'none'");
});

it('allows the running Vite dev server for scripts, styles, fonts and images', function (): void {
    // Earlier tests may have swapped Vite for a stub; bind a real instance for this check.
    $vite = new Illuminate\Foundation\Vite;
    app()->instance(Illuminate\Foundation\Vite::class, $vite);
    Vite::clearResolvedInstance();

    $hotFile = tempnam(sys_get_temp_dir(), 'vite-hot-');
    file_put_contents($hotFile, "https://signaculum-core.test:5173\n");
    $vite->useHotFile($hotFile);

    try {
        $headers = securityHeadersForWebPage();
    } finally {
        unlink($hotFile);
    }

    foreach (['script-src', 'style-src', 'font-src', 'img-src'] as $directiveName) {
        expect(contentSecurityPolicyDirective($headers, $directiveName))->toContain('https://signaculum-core.test:5173');
    }
});

it('returns the SECURITY_CSP override verbatim', function (): void {
    config(['security.csp' => "  default-src 'none'  "]);

    expect(securityHeadersForWebPage()->get('Content-Security-Policy'))->toBe("default-src 'none'");
});

it('skips headers when SECURITY_HEADERS_ENABLED is false', function (): void {
    config(['security.enabled' => false]);

    $headers = securityHeadersForWebPage();

    expect($headers->has('X-Frame-Options'))->toBeFalse()
        ->and($headers->has('Content-Security-Policy'))->toBeFalse();
});
