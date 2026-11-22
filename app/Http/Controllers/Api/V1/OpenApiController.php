<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Serves the hand-written Admin REST API OpenAPI 3.1 document as JSON.
 */
final class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $path = resource_path('openapi/admin-v1.json');

        if (! is_readable($path)) {
            throw new RuntimeException('Admin OpenAPI document is missing.');
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Admin OpenAPI document is invalid JSON.');
        }

        return response()->json($decoded);
    }
}
