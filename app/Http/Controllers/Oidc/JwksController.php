<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Services\SigningKeyService;
use Illuminate\Http\JsonResponse;

/**
 * `GET /.well-known/jwks.json`: public keys that verify id_token signatures.
 *
 * Cached briefly: clients refetch on an unknown `kid` anyway, and a short max-age keeps
 * a rotated key visible quickly.
 */
final class JwksController
{
    public function __invoke(SigningKeyService $signingKeyService): JsonResponse
    {
        return response()
            ->json($signingKeyService->publicKeySet(), options: JSON_UNESCAPED_SLASHES)
            ->header('Content-Type', 'application/jwk-set+json')
            ->header('Cache-Control', 'public, max-age=300');
    }
}
