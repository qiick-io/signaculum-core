<?php

declare(strict_types=1);

namespace App\Oidc;

use DateInterval;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Passport's refresh token grant, keeping each refreshed access token in the token
 * family of the authorization code it descends from (`auth_code_id`).
 *
 * Refresh tokens rotate, so without this link a replayed code (see OidcAuthCodeGrant)
 * could only revoke the tokens issued at the code exchange, and a leaked code's
 * holder could keep access by refreshing once (RFC 9700 §4.5).
 *
 * A refresh runs in one transaction that first locks the family root (the code row),
 * the same lock the replay revocation takes: a refresh in flight while a replay
 * revokes the family either finishes first (and its new tokens are revoked with the
 * family) or starts after (and finds its refresh token revoked). The refresh token is
 * then claimed atomically, so two parallel refreshes of one token yield one winner
 * (RFC 9700 §4.14.2: refresh tokens are single use when they rotate).
 */
final class OidcRefreshTokenGrant extends RefreshTokenGrant
{
    /**
     * The code whose token family the current refresh belongs to; the grant handles
     * one request at a time.
     */
    private ?string $familyAuthCodeId = null;

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $this->familyAuthCodeId = null;

        return DB::transaction(
            fn (): ResponseTypeInterface => parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL),
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OAuthServerException `invalid_grant` when the refresh token was revoked or
     *                              claimed by a parallel refresh
     */
    protected function validateOldRefreshToken(ServerRequestInterface $request, string $clientId): array
    {
        $refreshTokenData = parent::validateOldRefreshToken($request, $clientId);
        $previousAccessTokenId = $refreshTokenData['access_token_id'] ?? null;
        $authCodeId = is_string($previousAccessTokenId)
            ? Passport::token()->newQuery()->whereKey($previousAccessTokenId)->value('auth_code_id')
            : null;

        if (is_string($authCodeId)) {
            Passport::authCode()->newQuery()->whereKey($authCodeId)->lockForUpdate()->value('id');
            $this->familyAuthCodeId = $authCodeId;
        }

        $claimedRefreshTokenCount = Passport::refreshToken()->newQuery()
            ->whereKey($refreshTokenData['refresh_token_id'] ?? null)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        if ($claimedRefreshTokenCount !== 1) {
            throw OAuthServerException::invalidRefreshToken('Token has been revoked');
        }

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

        if ($this->familyAuthCodeId !== null) {
            Passport::token()->newQuery()
                ->whereKey($accessToken->getIdentifier())
                ->update(['auth_code_id' => $this->familyAuthCodeId]);
        }

        return $accessToken;
    }
}
