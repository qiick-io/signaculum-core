<?php

declare(strict_types=1);

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Oidc\EndSessionController;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Route;

/*
 * Signaculum has no public landing page: the root sends people to their account.
 */
Route::redirect('/', '/dashboard')->name('home');

// Uploaded logo and favicon (Settings → Appearance): public, the login page needs them.
Route::get('branding/{asset}', [BrandingController::class, 'show'])
    ->whereIn('asset', array_keys(SettingsRepositoryService::BRANDING_ASSETS))
    ->name('branding.show');

/*
 * RP-initiated logout (OIDC RP-Initiated Logout 1.0). Web middleware: needs the
 * browser session. CSRF is exempt for the endpoint itself so RPs can POST.
 */
Route::match(['get', 'post'], 'oauth/end-session', EndSessionController::class)
    ->name('oidc.end-session');
Route::post('oauth/end-session/confirm', [EndSessionController::class, 'confirm'])
    ->name('oidc.end-session.confirm');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
