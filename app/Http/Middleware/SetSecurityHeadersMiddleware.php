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
            $response->headers->set(
                'Content-Security-Policy',
                $this->contentSecurityPolicy($request),
            );
        }

        return $response;
    }

    /**
     * Build the default CSP, or return the SECURITY_CSP override verbatim.
     *
     * `frame-src` is omitted globally (browsers fall back to `default-src 'self'`).
     * Only the front-channel logout interstitial sets
     * `security.frontchannel_frame_origins` so those RP origins are allowed
     * on that page alone (OIDC Front-Channel Logout 1.0 §2).
     */
    private function contentSecurityPolicy(Request $request): string
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

        $directives = [
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
        ];

        $frameOrigins = $request->attributes->get('security.frontchannel_frame_origins');

        if (is_array($frameOrigins) && $frameOrigins !== []) {
            $allowedFrameOrigins = array_values(array_filter(
                $frameOrigins,
                static fn (mixed $origin): bool => is_string($origin) && $origin !== '',
            ));

            if ($allowedFrameOrigins !== []) {
                $directives[] = 'frame-src '.implode(' ', array_merge(["'self'"], $allowedFrameOrigins));
            }
        }

        return implode('; ', $directives);
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
