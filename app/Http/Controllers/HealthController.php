<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\HealthReadinessService;
use Illuminate\Http\JsonResponse;

/**
 * Auth-free liveness and readiness probes for Docker and load balancers.
 *
 * These routes are not a page. The signed-in UI does not render them.
 */
final class HealthController extends Controller
{
    public function __construct(
        private readonly HealthReadinessService $healthReadinessService,
    ) {}

    /**
     * Liveness: the process can answer HTTP. No dependency checks.
     */
    public function live(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ]);
    }

    /**
     * Readiness: the database accepts a query. HTTP 503 when it does not.
     */
    public function ready(): JsonResponse
    {
        $checks = $this->healthReadinessService->run();
        $isReady = ! in_array('fail', $checks, true);

        return response()->json([
            'status' => $isReady ? 'ok' : 'fail',
            'checks' => $checks,
        ], $isReady ? 200 : 503);
    }
}
