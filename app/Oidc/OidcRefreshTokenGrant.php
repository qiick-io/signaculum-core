<?php

declare(strict_types=1);

namespace App\Oidc;

use DateInterval;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Passport's refresh token grant, keeping each refreshed access token in the token
 * family of the authorization code it descends from (`auth_code_id`).
 *
 * Refresh tokens rotate, so without this link a replayed code (see OidcAuthCodeGrant)
 * could only revoke the tokens issued at the code exchange, and a leaked code's
 * holder could keep access by refreshing once (RFC 9700 §4.5).
 */
final class OidcRefreshTokenGrant extends RefreshTokenGrant
{
    /**
     * The access token the presented refresh token belongs to; the grant handles one
     * request at a time.
     */
    private ?string $previousAccessTokenId = null;

    /**
     * @return array<string, mixed>
     */
    protected function validateOldRefreshToken(ServerRequestInterface $request, string $clientId): array
    {
        $refreshTokenData = parent::validateOldRefreshToken($request, $clientId);
        $this->previousAccessTokenId = is_string($refreshTokenData['access_token_id'] ?? null)
            ? $refreshTokenData['access_token_id']
            : null;

        return $refreshTokenData;
    }

    /**
     * @param  array<ScopeEntityInterface>  $scopes
     */
    protected function issueAccessToken(
        DateInterval $accessTokenTTL,
        ClientEntityInterface $client,
        ?string $userIdentifier,
        array $scopes = [],
    ): AccessTokenEntityInterface {
        $accessToken = parent::issueAccessToken($accessTokenTTL, $client, $userIdentifier, $scopes);

        $authCodeId = $this->previousAccessTokenId === null
            ? null
            : Passport::token()->newQuery()->whereKey($this->previousAccessTokenId)->value('auth_code_id');

        if (is_string($authCodeId)) {
            Passport::token()->newQuery()
                ->whereKey($accessToken->getIdentifier())
                ->update(['auth_code_id' => $authCodeId]);
        }

        return $accessToken;
    }
}
