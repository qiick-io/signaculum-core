<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Services\TokenIntrospectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /oauth/introspect` (RFC 7662 §2): confidential clients ask whether a
 * token is active and receive the standard claim set when it is.
 */
final class IntrospectionController
{
    public function __invoke(Request $request, TokenIntrospectionService $tokenIntrospectionService): JsonResponse
    {
        if ($tokenIntrospectionService->authenticateClient($request) === null) {
            return response()
                ->json(['error' => 'invalid_client'], 401)
                ->header('Cache-Control', 'no-store');
        }

        $token = $request->input('token');

        if (! is_string($token) || $token === '') {
            return response()
                ->json(['error' => 'invalid_request'], 400)
                ->header('Cache-Control', 'no-store');
        }

        $tokenTypeHint = $request->input('token_type_hint');

        return response()
            ->json($tokenIntrospectionService->introspect(
                $token,
                is_string($tokenTypeHint) ? $tokenTypeHint : null,
            ))
            ->header('Cache-Control', 'no-store');
    }
}
