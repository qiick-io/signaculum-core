<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Services\PushedAuthorizationRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * `POST /oauth/par` (RFC 9126): accept a pushed authorization request and return
 * a single-use `request_uri` for a later `/oauth/authorize` call.
 */
final class PushedAuthorizationRequestController
{
    public function __invoke(
        Request $request,
        PushedAuthorizationRequestService $pushedAuthorizationRequestService,
    ): JsonResponse {
        try {
            $client = $pushedAuthorizationRequestService->authenticateClient($request);
            $pushedAuthorizationRequest = $pushedAuthorizationRequestService->store($client, $request);
        } catch (OAuthServerException $exception) {
            return $this->oauthErrorResponse($exception);
        }

        return response()
            ->json([
                'request_uri' => $pushedAuthorizationRequest->requestUri,
                'expires_in' => $pushedAuthorizationRequest->expiresIn,
            ], 201)
            ->header('Cache-Control', 'no-cache, no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * RFC 9126 §2.3: PAR errors use the token-endpoint JSON shape (RFC 6749 §5.2).
     */
    private function oauthErrorResponse(OAuthServerException $exception): JsonResponse
    {
        $payload = $exception->getPayload();
        $status = $exception->getHttpStatusCode();

        $response = response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');

        if ($status === 401) {
            $response->header('WWW-Authenticate', 'Basic realm="oauth"');
        }

        return $response;
    }
}
