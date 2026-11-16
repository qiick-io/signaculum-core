<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AccountExportController;
use App\Http\Controllers\Settings\AppearanceSettingsController;
use App\Http\Controllers\Settings\BrowserSessionController;
use App\Http\Controllers\Settings\ConnectedApplicationController;
use App\Http\Controllers\Settings\InstanceEmailTemplatesController;
use App\Http\Controllers\Settings\InstanceLocalizationSettingsController;
use App\Http\Controllers\Settings\InstanceSecuritySettingsController;
use App\Http\Controllers\Settings\InstanceSettingsController;
use App\Http\Controllers\Settings\LocaleController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Models\Setting;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('settings/profile/export', AccountExportController::class)
        ->middleware('throttle:account-export')
        ->name('profile.export');

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

    // Instance settings, as Excubia's project settings, for instance admins (SettingPolicy).
    Route::middleware('can:update,'.Setting::class)->group(function () {
        Route::get('settings/instance', [InstanceSettingsController::class, 'edit'])->name('instance.edit');
        Route::put('settings/instance', [InstanceSettingsController::class, 'update'])->name('instance.update');

        Route::get('settings/instance/localization', [InstanceLocalizationSettingsController::class, 'edit'])->name('instance.localization.edit');
        Route::put('settings/instance/localization', [InstanceLocalizationSettingsController::class, 'update'])->name('instance.localization.update');

        Route::get('settings/instance/security', [InstanceSecuritySettingsController::class, 'edit'])->name('instance.security.edit');
        Route::put('settings/instance/security', [InstanceSecuritySettingsController::class, 'update'])->name('instance.security.update');

        Route::get('settings/instance/email-templates', [InstanceEmailTemplatesController::class, 'edit'])->name('instance.email-templates.edit');
        Route::put('settings/instance/email-templates', [InstanceEmailTemplatesController::class, 'update'])->name('instance.email-templates.update');

        Route::get('settings/appearance', [AppearanceSettingsController::class, 'edit'])->name('appearance.edit');
        Route::put('settings/appearance', [AppearanceSettingsController::class, 'update'])->name('appearance.update');
    });
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
