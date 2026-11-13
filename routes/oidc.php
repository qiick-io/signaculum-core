<?php

declare(strict_types=1);

use App\Http\Controllers\Oidc\DiscoveryController;
use App\Http\Controllers\Oidc\IntrospectionController;
use App\Http\Controllers\Oidc\JwksController;
use App\Http\Controllers\Oidc\RevokeTokenController;
use App\Http\Controllers\Oidc\UserinfoController;
use Illuminate\Support\Facades\Route;

/*
 * OpenID Connect endpoints on top of Passport's `/oauth/authorize` and `/oauth/token`.
 * Loaded with the stateless `api` middleware group and no URL prefix (bootstrap/app.php).
 */
Route::get('.well-known/openid-configuration', DiscoveryController::class)->name('oidc.discovery');
Route::get('.well-known/jwks.json', JwksController::class)->name('oidc.jwks');

Route::match(['get', 'post'], 'oauth/userinfo', UserinfoController::class)
    ->middleware('auth:api')
    ->name('oidc.userinfo');

Route::post('oauth/introspect', IntrospectionController::class)
    ->middleware('throttle:oidc-introspect')
    ->name('oidc.introspect');

Route::post('oauth/revoke', RevokeTokenController::class)
    ->middleware('throttle:oidc-token')
    ->name('oidc.revoke');
