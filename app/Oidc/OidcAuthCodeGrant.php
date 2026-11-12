<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Data\AuthorizationContextData;
use App\Enums\OidcScopeEnum;
use App\Services\PendingAuthorizationService;
use DateInterval;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Passport's authorization code grant with the qiick OpenID Connect profile on top.
 *
 * Authorization request: PKCE must use S256 and, unless relaxed in `config/oidc.php`,
 * is required for every client (RFC 7636, RFC 9700 §2.1.1); `openid` requests must
 * carry a `nonce`. The nonce is kept for the code (see PendingAuthorizationService).
 *
 * Token request: after league has validated the code and its verifier, the stored
 * context of that code is handed to the IdTokenResponse.
 */
final class OidcAuthCodeGrant extends AuthCodeGrant
{
    public function __construct(
        AuthCodeRepositoryInterface $authCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $authCodeTTL,
        private readonly PendingAuthorizationService $pendingAuthorizationService,
    ) {
        parent::__construct($authCodeRepository, $refreshTokenRepository, $authCodeTTL);
    }

    /**
     * @throws OAuthServerException redirected to the client (with `state`) when PKCE or
     *                              the nonce do not follow the profile
     */
    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        $authorizationRequest = parent::validateAuthorizationRequest($request);

        $codeChallengeMethod = $authorizationRequest->getCodeChallengeMethod();
        $hasCodeChallenge = $authorizationRequest->getCodeChallenge() !== null;

        if ($hasCodeChallenge && $codeChallengeMethod !== 'S256') {
            throw $this->redirectedInvalidRequest($authorizationRequest, 'Code challenge method must be `S256`.');
        }

        if (! $hasCodeChallenge && (bool) config('oidc.require_pkce')) {
            throw $this->redirectedInvalidRequest($authorizationRequest, 'PKCE is required: send `code_challenge` with `code_challenge_method=S256`.');
        }

        $nonce = $this->getQueryStringParameter('nonce', $request);
        $isOpenIdRequest = $this->hasOpenIdScope($authorizationRequest->getScopes());

        if ($isOpenIdRequest && $nonce === null && (bool) config('oidc.require_nonce')) {
            throw $this->redirectedInvalidRequest($authorizationRequest, 'A `nonce` is required for OpenID Connect requests.');
        }

        $this->pendingAuthorizationService->remember(
            $authorizationRequest->getClient()->getIdentifier(),
            $isOpenIdRequest ? $nonce : null,
        );

        return $authorizationRequest;
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $responseType = parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);

        if ($responseType instanceof IdTokenResponse) {
            // The code was decrypted and validated by the parent call, so this cannot fail.
            $authCodePayload = json_decode($this->decrypt((string) $this->getRequestParameter('code', $request)));

            $responseType->setAuthorizationContext($this->storedContext((string) $authCodePayload->auth_code_id));
        }

        return $responseType;
    }

    private function storedContext(string $authCodeId): AuthorizationContextData
    {
        $authCode = Passport::authCode()->newQuery()->whereKey($authCodeId)->first(['nonce', 'auth_time']);

        return new AuthorizationContextData(
            nonce: $authCode?->getAttribute('nonce'),
            authTime: $authCode?->getAttribute('auth_time') === null ? null : (int) $authCode->getAttribute('auth_time'),
        );
    }

    /**
     * @param  array<ScopeEntityInterface>  $scopes
     */
    private function hasOpenIdScope(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ($scope->getIdentifier() === OidcScopeEnum::OpenId->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * An `invalid_request` error that league renders as a redirect to the validated
     * redirect URI, with `state` echoed (RFC 6749 §4.1.2.1).
     */
    private function redirectedInvalidRequest(AuthorizationRequestInterface $authorizationRequest, string $hint): OAuthServerException
    {
        $redirectUri = $authorizationRequest->getRedirectUri() ?? $this->getClientRedirectUri($authorizationRequest->getClient());
        $state = $authorizationRequest->getState();

        return new OAuthServerException(
            'The request is missing a required parameter, includes an invalid parameter value, includes a parameter more than once, or is otherwise malformed.',
            3,
            'invalid_request',
            400,
            $hint,
            $this->makeRedirectUri($redirectUri, $state !== null ? ['state' => $state] : []),
        );
    }
}
