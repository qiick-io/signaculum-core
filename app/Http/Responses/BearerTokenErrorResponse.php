<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The refusals of the Admin REST API's Bearer key check, in one shape for every path
 * (RFC 6750 §3, app-ui docs/api-keys.md behaviour 7): a `WWW-Authenticate: Bearer` challenge
 * with the error code, and `{"message": …, "error": …}` in the body.
 */
final class BearerTokenErrorResponse
{
    /**
     * `401 invalid_token`: missing, unknown, expired or revoked key, or one that may do nothing
     * (RFC 6750 §3.1). A missing key gets the code too, so every refusal has one shape.
     */
    public static function invalidToken(string $message): JsonResponse
    {
        return response()
            ->json(['message' => $message, 'error' => 'invalid_token'], 401)
            ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
    }

    /**
     * `403 insufficient_scope` with the scope (or any of the scopes) the request needed
     * (RFC 6750 §3.1).
     *
     * @param  list<string>  $requiredScopes
     */
    public static function insufficientScope(string $message, array $requiredScopes): JsonResponse
    {
        $scope = implode(' ', $requiredScopes);

        return response()
            ->json(['message' => $message, 'error' => 'insufficient_scope', 'scope' => $scope], 403)
            ->header('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="'.$scope.'"');
    }
}
