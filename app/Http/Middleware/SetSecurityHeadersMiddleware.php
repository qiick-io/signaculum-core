<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense headers for Inertia/web HTML. Skips OIDC discovery/API JSON and
 * responses that already set Content-Security-Policy.
 *
 * Copied from externa-core's SetSecurityHeadersMiddleware (app-ui
 * `security-headers.md`); branding assets are same-origin so no files-disk
 * origin is required. HSTS stays on the reverse proxy.
 */
final class SetSecurityHeadersMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! (bool) config('security.enabled', true)) {
            return $response;
        }

        if ($request->is('api/*') || $request->is('.well-known/*') || $request->is('oauth/userinfo')) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set(
            'X-Frame-Options',
            (string) config('security.x_frame_options', 'DENY'),
        );
        $response->headers->set(
            'Referrer-Policy',
            (string) config('security.referrer_policy', 'strict-origin-when-cross-origin'),
        );
        $response->headers->set(
            'Permissions-Policy',
            (string) config('security.permissions_policy'),
        );

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        }

        return $response;
    }

    /**
     * Build the default CSP, or return the SECURITY_CSP override verbatim.
     */
    private function contentSecurityPolicy(): string
    {
        $override = config('security.csp');

        if (is_string($override) && trim($override) !== '') {
            return trim($override);
        }

        $developmentServerOrigins = $this->originsOf([Vite::devServerUrl()]);

        $scriptSources = array_merge(["'self'", "'unsafe-inline'", "'unsafe-eval'"], $developmentServerOrigins);
        $styleSources = array_merge(["'self'", "'unsafe-inline'"], $developmentServerOrigins);
        $fontSources = array_merge(["'self'", 'data:'], $developmentServerOrigins);
        $imageSources = array_merge(["'self'", 'data:', 'blob:', 'https:'], $developmentServerOrigins);

        return implode('; ', [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSources),
            'style-src '.implode(' ', $styleSources),
            'img-src '.implode(' ', array_unique($imageSources)),
            'font-src '.implode(' ', $fontSources),
            "connect-src 'self' ws: wss: http: https:",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

    /**
     * Reduce absolute http(s) URLs to CSP source origins (`scheme://host[:port]`).
     *
     * @param  list<mixed>  $urls
     * @return list<string>
     */
    private function originsOf(array $urls): array
    {
        $origins = [];

        foreach ($urls as $url) {
            if (! is_string($url)) {
                continue;
            }

            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);

            if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
                continue;
            }

            $origins[] = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
        }

        return $origins;
    }
}
