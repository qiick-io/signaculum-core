<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\OrganizationController;
use App\Http\Controllers\Api\V1\OrganizationMembershipController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\UserLifecycleController;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Http\Middleware\EnsureApiKeyScopeMiddleware;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('openapi.json', OpenApiController::class)->name('api.v1.openapi');

    Route::middleware([
        'throttle:api',
        AuthenticateApiKeyMiddleware::class,
    ])->group(function (): void {
        Route::middleware([EnsureApiKeyScopeMiddleware::class.':users:read'])->group(function (): void {
            Route::get('users', [UserController::class, 'index'])->name('api.v1.users.index');
            Route::get('users/{user:subject}', [UserController::class, 'show'])->name('api.v1.users.show');
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':users:write'])->group(function (): void {
            Route::post('users', [UserController::class, 'store'])->name('api.v1.users.store');
            Route::patch('users/{user:subject}', [UserController::class, 'update'])->name('api.v1.users.update');
            Route::post('users/{user:subject}/deactivate', [UserLifecycleController::class, 'deactivate'])
                ->name('api.v1.users.deactivate');
            Route::post('users/{user:subject}/reset-two-factor', [UserLifecycleController::class, 'resetTwoFactor'])
                ->name('api.v1.users.reset-two-factor');
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':organizations:read'])->group(function (): void {
            Route::get('organizations', [OrganizationController::class, 'index'])->name('api.v1.organizations.index');
            Route::get('organizations/{organization}', [OrganizationController::class, 'show'])
                ->name('api.v1.organizations.show');
            Route::get('organizations/{organization}/memberships', [OrganizationMembershipController::class, 'index'])
                ->name('api.v1.organizations.memberships.index');
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':organizations:write'])->group(function (): void {
            Route::post('organizations', [OrganizationController::class, 'store'])->name('api.v1.organizations.store');
            Route::post('organizations/{organization}/memberships', [OrganizationMembershipController::class, 'store'])
                ->name('api.v1.organizations.memberships.store');
            Route::patch('organizations/{organization}/memberships/{userId}', [OrganizationMembershipController::class, 'update'])
                ->name('api.v1.organizations.memberships.update');
            Route::delete('organizations/{organization}/memberships/{userId}', [OrganizationMembershipController::class, 'destroy'])
                ->name('api.v1.organizations.memberships.destroy');
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':applications:read'])->group(function (): void {
            Route::get('applications', [ApplicationController::class, 'index'])->name('api.v1.applications.index');
            Route::get('applications/{application}', [ApplicationController::class, 'show'])
                ->name('api.v1.applications.show');
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':applications:write'])->group(function (): void {
            Route::post('applications', [ApplicationController::class, 'store'])->name('api.v1.applications.store');
            Route::post('applications/{application}/rotate-secret', [ApplicationController::class, 'rotateSecret'])
                ->name('api.v1.applications.rotate-secret');
        });
    });
});
