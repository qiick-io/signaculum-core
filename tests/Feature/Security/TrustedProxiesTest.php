<?php

declare(strict_types=1);

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * TRUSTED_PROXIES (config `app.trusted_proxies`) decides whether X-Forwarded-* headers from a
 * TLS terminator are honoured. Without it a client could spoof the scheme or host; with it the
 * issuer-facing URLs behind the proxy stay https (OIDC Core §3.1.2.1 requires TLS).
 */
function bootWithTrustedProxies(?string $trustedProxies): void
{
    if ($trustedProxies === null) {
        putenv('TRUSTED_PROXIES');
    } else {
        putenv("TRUSTED_PROXIES={$trustedProxies}");
    }

    test()->refreshApplication();

    Route::get('/_test/forwarded-scheme', fn (Request $request) => response()->json([
        'is_secure' => $request->isSecure(),
        'host' => $request->getHost(),
    ]));
}

afterEach(function (): void {
    putenv('TRUSTED_PROXIES');
    TrustProxies::flushState();
});

test('forwarded headers are ignored when no proxy is trusted', function (): void {
    bootWithTrustedProxies(null);

    $this->get('http://localhost/_test/forwarded-scheme', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'spoofed.example'])
        ->assertJson(['is_secure' => false])
        ->assertJsonMissing(['host' => 'spoofed.example']);
});

test('forwarded scheme and host are honoured from a trusted proxy', function (): void {
    bootWithTrustedProxies('*');

    $this->get('http://localhost/_test/forwarded-scheme', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'idp.example'])
        ->assertJson(['is_secure' => true, 'host' => 'idp.example']);
});

test('a proxy outside the trusted list is not honoured', function (): void {
    bootWithTrustedProxies('10.0.0.1, 10.0.0.2');

    $this->get('http://localhost/_test/forwarded-scheme', ['X-Forwarded-Proto' => 'https'])
        ->assertJson(['is_secure' => false]);
});
