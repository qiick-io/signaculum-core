<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Data\AuthorizationContextData;
use App\Data\TokenFamilyData;
use App\Enums\TokenFamilyRootEnum;
use App\Services\TokenFamilyService;
use DateInterval;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\DeviceCodeGrant;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Passport's device code grant with the qiick OpenID Connect profile on top.
 *
 * After league issues the access token (RFC 8628 §3.4 / §3.5), the OpenID Connect
 * context stored on the device code at approval is handed to {@see IdTokenResponse}
 * so an `id_token` can carry truthful `auth_time` / `amr` / `sid` from the approving
 * session (OIDC Core §2, §3.1.3.6; RFC 8176). There is no `nonce` on the device
 * authorization request (RFC 8628).
 *
 * The issued access token carries the device code's id (`device_code_id`), and
 * OidcRefreshTokenGrant passes it on: the device code is the root of a token family,
 * so a reused device refresh token revokes the family (RFC 9700 §2.2.2, §4.14.2;
 * device clients are usually public).
 *
 * A device code is single use (RFC 8628 §3.5). Presenting a redeemed one again means it
 * leaked, as for an authorization code (RFC 6749 §4.1.2, RFC 9700 §4.5; see
 * OidcAuthCodeGrant): its whole family is revoked before league answers `invalid_grant`.
 * Holding the code is enough, by design. Two parallel polls of an approved code count as
 * a replay too: the loser revokes the winner's tokens.
 */
final class OidcDeviceCodeGrant extends DeviceCodeGrant
{
    /**
     * The device code of the current token request; the grant handles one request at a time.
     */
    private ?string $redeemingDeviceCodeId = null;

    public function __construct(
        DeviceCodeRepositoryInterface $deviceCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $deviceCodeTTL,
        string $verificationUri,
        private readonly TokenFamilyService $tokenFamilyService,
        int $retryInterval = 5,
    ) {
        parent::__construct(
            $deviceCodeRepository,
            $refreshTokenRepository,
            $deviceCodeTTL,
            $verificationUri,
            $retryInterval,
        );
    }

    /**
     * @throws OAuthServerException `invalid_grant` for a replayed device code, after the
     *                              tokens of its family have been revoked
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $this->redeemingDeviceCodeId = null;
        $deviceCodeId = (string) $this->getRequestParameter('device_code', $request);

        try {
            $responseType = parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
        } catch (OAuthServerException $exception) {
            $this->revokeTokenFamilyOfRedeemedDeviceCode($deviceCodeId);

            throw $exception;
        }

        if ($responseType instanceof IdTokenResponse && $deviceCodeId !== '') {
            $responseType->setAuthorizationContext($this->storedContext($deviceCodeId));
        }

        return $responseType;
    }

    /**
     * A redeemed device code is revoked; a code still pending, denied without tokens or
     * merely polled too soon leaves nothing to revoke. The check runs under the family
     * lock, so a refresh in flight finishes first and its tokens are revoked too.
     */
    private function revokeTokenFamilyOfRedeemedDeviceCode(string $deviceCodeId): void
    {
        if ($deviceCodeId === '') {
            return;
        }

        $this->tokenFamilyService->revokeWhenReused(
            new TokenFamilyData(TokenFamilyRootEnum::DeviceCode, $deviceCodeId),
            fn (): bool => (bool) Passport::deviceCode()->newQuery()->whereKey($deviceCodeId)->value('revoked'),
        );
    }

    protected function validateDeviceCode(ServerRequestInterface $request, ClientEntityInterface $client): DeviceCodeEntityInterface
    {
        $deviceCode = parent::validateDeviceCode($request, $client);
        $this->redeemingDeviceCodeId = $deviceCode->getIdentifier();

        return $deviceCode;
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

        if ($this->redeemingDeviceCodeId !== null) {
            $this->tokenFamilyService->tag(
                $accessToken->getIdentifier(),
                new TokenFamilyData(TokenFamilyRootEnum::DeviceCode, $this->redeemingDeviceCodeId),
            );
        }

        return $accessToken;
    }

    private function storedContext(string $deviceCodeId): AuthorizationContextData
    {
        $deviceCode = Passport::deviceCode()->newQuery()->whereKey($deviceCodeId)->first([
            'auth_time',
            'oidc_session_id',
            'authentication_methods',
        ]);

        return new AuthorizationContextData(
            nonce: null,
            authTime: $deviceCode?->getAttribute('auth_time') === null ? null : (int) $deviceCode->getAttribute('auth_time'),
            oidcSessionId: $deviceCode?->getAttribute('oidc_session_id'),
            authenticationMethods: $this->authenticationMethodsFrom($deviceCode?->getAttribute('authentication_methods')),
            isAcrRequested: false,
        );
    }

    /**
     * @return list<string>
     */
    private function authenticationMethodsFrom(mixed $storedMethods): array
    {
        if (is_string($storedMethods) && $storedMethods !== '') {
            $decodedMethods = json_decode($storedMethods, true);
            $storedMethods = is_array($decodedMethods) ? $decodedMethods : [];
        }

        if (! is_array($storedMethods)) {
            return [];
        }

        $authenticationMethods = [];

        foreach ($storedMethods as $storedMethod) {
            if (is_string($storedMethod) && $storedMethod !== '') {
                $authenticationMethods[] = $storedMethod;
            }
        }

        return $authenticationMethods;
    }
}
