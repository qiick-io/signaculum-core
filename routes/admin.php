<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
 * Instance-admin surfaces. Authorization is per request (UserPolicy); the UI only hides the nav.
 */
Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
});
