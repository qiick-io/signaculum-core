<?php

declare(strict_types=1);

/**
 * Auth-free liveness and readiness probes (Docker, load balancers).
 */

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
