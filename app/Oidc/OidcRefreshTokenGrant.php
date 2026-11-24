<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Data\TokenFamilyData;
use App\Services\TokenFamilyService;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Passport's refresh token grant, keeping each refreshed access token in the token
 * family of the authorization code or device code it descends from (TokenFamilyService).
 *
 * Refresh tokens rotate, so without this link a replayed code (see OidcAuthCodeGrant)
 * could only revoke the tokens issued at the code exchange, and a leaked code's
 * holder could keep access by refreshing once (RFC 9700 §4.5).
 *
 * A refresh runs in one transaction that first locks the family root (the code or device code row),
 * the same lock the replay revocation takes: a refresh in flight while a replay
 * revokes the family either finishes first (and its new tokens are revoked with the
 * family) or starts after (and finds its refresh token revoked). The refresh token is
 * then claimed atomically, so two parallel refreshes of one token yield one winner.
 *
 * Refresh token reuse (RFC 9700 §4.14.2): a rotated refresh token is single use, so
 * presenting one that is already revoked means it leaked, and the authorization server
 * cannot tell the attacker from the legitimate client. The whole family is revoked
 * before league answers `invalid_grant`, which forces both back to a new authorization.
 * That includes the loser of two parallel refreshes of one token, and a presenter that
 * fails client authentication: holding the token is enough, as for a replayed code.
 * Client credentials tokens have no refresh token, so no family.
 */
final class OidcRefreshTokenGrant extends RefreshTokenGrant
{
    /**
     * The token family the current refresh belongs to; the grant handles one request
     * at a time.
     */
    private ?TokenFamilyData $family = null;

    public function __construct(
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        private readonly TokenFamilyService $tokenFamilyService,
    ) {
        parent::__construct($refreshTokenRepository);
    }

    /**
     * @throws OAuthServerException `invalid_grant` for a reused refresh token, after its
     *                              token family has been revoked
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $this->family = null;

        try {
            return DB::transaction(
                fn (): ResponseTypeInterface => parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL),
            );
        } catch (OAuthServerException $exception) {
            $this->revokeTokenFamilyOfReusedRefreshToken($request);

            throw $exception;
        }
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
        $this->family = $this->tokenFamilyService->familyOfAccessToken($refreshTokenData['access_token_id'] ?? null);

        if ($this->family !== null) {
            $this->tokenFamilyService->lock($this->family);
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

        if ($this->family !== null) {
            $this->tokenFamilyService->tag($accessToken->getIdentifier(), $this->family);
        }

        return $accessToken;
    }

    /**
     * A refresh token that is revoked once the failed refresh rolled back was used
     * before (or revoked with its family): revoke what is left of the family. A refresh
     * that failed for another reason (expired, wrong scope) left its token unrevoked.
     */
    private function revokeTokenFamilyOfReusedRefreshToken(ServerRequestInterface $request): void
    {
        $presentedRefreshToken = $this->presentedRefreshTokenFrom($request);

        if ($presentedRefreshToken === null) {
            return;
        }

        $family = $this->tokenFamilyService->familyOfAccessToken($presentedRefreshToken['access_token_id']);

        if ($family === null) {
            return;
        }

        $this->tokenFamilyService->revokeWhenReused(
            $family,
            fn (): bool => (bool) Passport::refreshToken()->newQuery()
                ->whereKey($presentedRefreshToken['refresh_token_id'])
                ->value('revoked'),
        );
    }

    /**
     * The ids in the presented refresh token. Only tokens this server encrypted decrypt,
     * so the payload can be trusted; anything else yields null.
     *
     * @return array{refresh_token_id: string, access_token_id: string}|null
     */
    private function presentedRefreshTokenFrom(ServerRequestInterface $request): ?array
    {
        $encryptedRefreshToken = $this->getRequestParameter('refresh_token', $request);

        if (! is_string($encryptedRefreshToken)) {
            return null;
        }

        try {
            $refreshTokenData = json_decode($this->decrypt($encryptedRefreshToken), true);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($refreshTokenData)) {
            return null;
        }

        $refreshTokenId = $refreshTokenData['refresh_token_id'] ?? null;
        $accessTokenId = $refreshTokenData['access_token_id'] ?? null;

        if (! is_string($refreshTokenId) || ! is_string($accessTokenId)) {
            return null;
        }

        return ['refresh_token_id' => $refreshTokenId, 'access_token_id' => $accessTokenId];
    }
}
