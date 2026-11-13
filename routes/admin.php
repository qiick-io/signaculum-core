<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\ApplicationSecretController;
use App\Http\Controllers\Admin\InstanceAdminController;
use App\Http\Controllers\Admin\UserActivationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserTwoFactorController;
use Illuminate\Support\Facades\Route;

/*
 * Instance-admin surfaces. Authorization is per request (policies); the UI only hides the nav.
 */
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::put('users/{user}/activation', [UserActivationController::class, 'update'])->name('users.activation.update');
    Route::delete('users/{user}/two-factor', [UserTwoFactorController::class, 'destroy'])->name('users.two-factor.destroy');
    Route::put('users/{user}/instance-admin', [InstanceAdminController::class, 'update'])->name('users.instance-admin.update');

    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
    Route::put('applications/{application}/secret', [ApplicationSecretController::class, 'update'])->name('applications.secret.update');
    Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');

    Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');
});
