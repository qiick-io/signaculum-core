<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\ApiKeySecretController;
use App\Http\Controllers\Admin\ApplicationController;
use App\Http\Controllers\Admin\ApplicationSecretController;
use App\Http\Controllers\Admin\InstanceAdminController;
use App\Http\Controllers\Admin\UserActivationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserGroupController;
use App\Http\Controllers\Admin\UserGroupTrashController;
use App\Http\Controllers\Admin\UserImpersonationController;
use App\Http\Controllers\Admin\UserLockoutController;
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
    Route::delete('users/{user}/lockout', [UserLockoutController::class, 'destroy'])->name('users.lockout.destroy');
    Route::delete('users/{user}/two-factor', [UserTwoFactorController::class, 'destroy'])->name('users.two-factor.destroy');
    Route::put('users/{user}/instance-admin', [InstanceAdminController::class, 'update'])->name('users.instance-admin.update');
    Route::post('users/{user}/impersonation', [UserImpersonationController::class, 'store'])->name('users.impersonation.store');

    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
    Route::put('applications/{application}/secret', [ApplicationSecretController::class, 'update'])->name('applications.secret.update');
    Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');

    Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');

    Route::get('groups', [UserGroupController::class, 'index'])->name('groups.index');
    Route::post('groups', [UserGroupController::class, 'store'])->name('groups.store');
    Route::put('groups/{group}', [UserGroupController::class, 'update'])->name('groups.update')->whereNumber('group');
    Route::delete('groups/{group}', [UserGroupController::class, 'destroy'])->name('groups.destroy')->whereNumber('group');
    Route::post('groups/{group}/restore', [UserGroupTrashController::class, 'restore'])->name('groups.restore')->withTrashed()->whereNumber('group');
    Route::delete('groups/{group}/force', [UserGroupTrashController::class, 'forceDestroy'])->name('groups.force-destroy')->withTrashed()->whereNumber('group');
    Route::post('groups/bulk', [UserGroupTrashController::class, 'bulk'])->name('groups.bulk');

    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::put('api-keys/{apiKey}/secret', [ApiKeySecretController::class, 'update'])->name('api-keys.secret.update');
    Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
});
