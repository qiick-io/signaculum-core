<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Services\DiscoveryDocumentService;
use Illuminate\Http\JsonResponse;

/**
 * `GET /.well-known/openid-configuration`.
 */
final class DiscoveryController
{
    public function __invoke(DiscoveryDocumentService $discoveryDocumentService): JsonResponse
    {
        return response()
            ->json($discoveryDocumentService->document(), options: JSON_UNESCAPED_SLASHES)
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
