<?php

use App\Http\Controllers\Settings\BrowserSessionController;
use App\Http\Controllers\Settings\ConnectedApplicationController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::patch('settings/locale', [LocaleController::class, 'update'])->name('locale.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/sessions', [BrowserSessionController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('sessions.index');
    Route::delete('settings/sessions/others', [BrowserSessionController::class, 'destroyOthers'])
        ->middleware(RequirePassword::class)
        ->name('sessions.destroy-others');
    Route::delete('settings/sessions/{publicId}', [BrowserSessionController::class, 'destroy'])
        ->middleware(RequirePassword::class)
        ->name('sessions.destroy');

    Route::get('settings/connected-applications', [ConnectedApplicationController::class, 'index'])->name('connected-applications.index');
    Route::delete('settings/connected-applications/{clientId}', [ConnectedApplicationController::class, 'destroy'])->name('connected-applications.destroy');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
