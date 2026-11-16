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
            Route::get('users', [UserController::class, 'index']);
            Route::get('users/{user}', [UserController::class, 'show']);
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':users:write'])->group(function (): void {
            Route::post('users', [UserController::class, 'store']);
            Route::patch('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/deactivate', [UserLifecycleController::class, 'deactivate']);
            Route::post('users/{user}/reset-two-factor', [UserLifecycleController::class, 'resetTwoFactor']);
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':organizations:read'])->group(function (): void {
            Route::get('organizations', [OrganizationController::class, 'index']);
            Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
            Route::get('organizations/{organization}/memberships', [OrganizationMembershipController::class, 'index']);
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':organizations:write'])->group(function (): void {
            Route::post('organizations', [OrganizationController::class, 'store']);
            Route::post('organizations/{organization}/memberships', [OrganizationMembershipController::class, 'store']);
            Route::patch('organizations/{organization}/memberships/{userId}', [OrganizationMembershipController::class, 'update']);
            Route::delete('organizations/{organization}/memberships/{userId}', [OrganizationMembershipController::class, 'destroy']);
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':applications:read'])->group(function (): void {
            Route::get('applications', [ApplicationController::class, 'index']);
            Route::get('applications/{application}', [ApplicationController::class, 'show']);
        });

        Route::middleware([EnsureApiKeyScopeMiddleware::class.':applications:write'])->group(function (): void {
            Route::post('applications', [ApplicationController::class, 'store']);
            Route::post('applications/{application}/rotate-secret', [ApplicationController::class, 'rotateSecret']);
        });
    });
});
