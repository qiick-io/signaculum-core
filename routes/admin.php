<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
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
 *
 * A route that binds a record also names the permission its policy requires: the `permission`
 * middleware runs before route-model binding (bootstrap/app.php), so a user without it gets 403
 * whether the record exists or not, instead of a 404 that reveals which ids exist.
 */
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->middleware('permission:'.PermissionEnum::CanEditUsers->value);
    Route::put('users/{user}/activation', [UserActivationController::class, 'update'])->name('users.activation.update')->middleware('permission:'.PermissionEnum::CanEditUsers->value);
    Route::delete('users/{user}/lockout', [UserLockoutController::class, 'destroy'])->name('users.lockout.destroy')->middleware('permission:'.PermissionEnum::CanEditUsers->value);
    Route::delete('users/{user}/two-factor', [UserTwoFactorController::class, 'destroy'])->name('users.two-factor.destroy')->middleware('permission:'.PermissionEnum::CanEditUsers->value);
    Route::put('users/{user}/instance-admin', [InstanceAdminController::class, 'update'])->name('users.instance-admin.update')->middleware('permission:'.PermissionEnum::CanEditUsers->value);
    Route::post('users/{user}/impersonation', [UserImpersonationController::class, 'store'])->name('users.impersonation.store')->middleware('permission:'.PermissionEnum::CanImpersonateUsers->value);

    Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
    Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update')->middleware('permission:'.PermissionEnum::CanEditApplications->value);
    Route::put('applications/{application}/secret', [ApplicationSecretController::class, 'update'])->name('applications.secret.update')->middleware('permission:'.PermissionEnum::CanEditApplications->value);
    Route::delete('applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy')->middleware('permission:'.PermissionEnum::CanDeleteApplications->value);

    Route::get('activity-logs', [ActivityLogController::class, 'index'])->name('activity-logs.index');

    Route::get('groups', [UserGroupController::class, 'index'])->name('groups.index');
    Route::post('groups', [UserGroupController::class, 'store'])->name('groups.store');
    Route::put('groups/{group}', [UserGroupController::class, 'update'])->name('groups.update')->middleware('permission:'.PermissionEnum::CanEditGroups->value)->whereNumber('group');
    Route::delete('groups/{group}', [UserGroupController::class, 'destroy'])->name('groups.destroy')->middleware('permission:'.PermissionEnum::CanDeleteGroups->value)->whereNumber('group');
    Route::post('groups/{group}/restore', [UserGroupTrashController::class, 'restore'])->name('groups.restore')->withTrashed()->whereNumber('group');
    Route::delete('groups/{group}/force', [UserGroupTrashController::class, 'forceDestroy'])->name('groups.force-destroy')->withTrashed()->whereNumber('group');
    Route::post('groups/bulk', [UserGroupTrashController::class, 'bulk'])->name('groups.bulk');

    Route::get('api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
    Route::post('api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::put('api-keys/{apiKey}/secret', [ApiKeySecretController::class, 'update'])->name('api-keys.secret.update')->middleware('permission:'.PermissionEnum::CanManageApiKeys->value);
    Route::delete('api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy')->middleware('permission:'.PermissionEnum::CanManageApiKeys->value);
});
