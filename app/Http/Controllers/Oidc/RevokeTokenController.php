<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Actions\RevokeOAuthTokenAction;
use App\Http\Requests\Oidc\RevokeTokenRequest;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\ClientRepository;

/**
 * `POST /oauth/revoke` (RFC 7009): clients present an access or refresh token to
 * invalidate it. Auth methods match the token endpoint (`client_secret_basic`,
 * `client_secret_post`, or `none` for public clients).
 */
final class RevokeTokenController
{
    public function __invoke(
        RevokeTokenRequest $request,
        ClientRepository $clientRepository,
        RevokeOAuthTokenAction $revokeOAuthTokenAction,
    ): JsonResponse {
        $clientCredentials = $this->clientCredentialsFrom($request);
        $clientId = $clientCredentials['client_id'];

        if ($clientId === null || $clientId === '') {
            return $this->invalidClientResponse();
        }

        $client = $clientRepository->findActive($clientId);

        if (! $client instanceof Client || ! $this->clientAuthenticates($client, $clientCredentials['client_secret'])) {
            return $this->invalidClientResponse();
        }

        $authenticatedClientId = (string) $client->getKey();

        if ($authenticatedClientId === '') {
            return $this->invalidClientResponse();
        }

        $revokeOAuthTokenAction->execute(
            $authenticatedClientId,
            (string) $request->validated('token'),
            $request->validated('token_type_hint'),
        );

        return response()
            ->json([])
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    /**
     * @return array{client_id: string|null, client_secret: string|null}
     */
    private function clientCredentialsFrom(RevokeTokenRequest $request): array
    {
        $authorization = $request->header('Authorization');

        if (is_string($authorization) && str_starts_with($authorization, 'Basic ')) {
            $decoded = base64_decode(substr($authorization, 6), true);

            if (is_string($decoded) && str_contains($decoded, ':')) {
                [$clientId, $clientSecret] = explode(':', $decoded, 2);

                return [
                    'client_id' => $clientId !== '' ? $clientId : null,
                    'client_secret' => $clientSecret !== '' ? $clientSecret : null,
                ];
            }
        }

        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');

        return [
            'client_id' => is_string($clientId) && $clientId !== '' ? $clientId : null,
            'client_secret' => is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null,
        ];
    }

    /**
     * Confidential clients must present a matching secret; public clients must not
     * send a secret (RFC 6749 §2.3 / RFC 7009 §2.1).
     */
    private function clientAuthenticates(Client $client, ?string $clientSecret): bool
    {
        if ($client->confidential()) {
            return is_string($clientSecret)
                && $clientSecret !== ''
                && is_string($client->secret)
                && Hash::check($clientSecret, $client->secret);
        }

        return $clientSecret === null;
    }

    private function invalidClientResponse(): JsonResponse
    {
        return response()
            ->json(['error' => 'invalid_client'], 401)
            ->header('WWW-Authenticate', 'Basic realm="oauth"')
            ->header('Cache-Control', 'no-store');
    }
}
