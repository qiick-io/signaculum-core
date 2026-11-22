<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Services\TokenIntrospectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /oauth/introspect` (RFC 7662 §2): confidential clients ask whether a
 * token is active and receive the standard claim set when it is.
 *
 * Active claims are returned only for tokens issued to the authenticated
 * caller; another client's token yields `{ "active": false }` (RFC 7662 §2.2 / §4).
 */
final class IntrospectionController
{
    public function __invoke(Request $request, TokenIntrospectionService $tokenIntrospectionService): JsonResponse
    {
        $callingClient = $tokenIntrospectionService->authenticateClient($request);

        if ($callingClient === null) {
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
                $callingClient,
                is_string($tokenTypeHint) ? $tokenTypeHint : null,
            ))
            ->header('Cache-Control', 'no-store');
    }
}
