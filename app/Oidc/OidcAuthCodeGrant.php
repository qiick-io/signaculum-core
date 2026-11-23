<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Data\AuthorizationContextData;
use App\Data\ClaimsRequestData;
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
 * A `claims` parameter (OIDC Core §5.5, limited: see ClaimsRequestData) adds the scope
 * of each requested claim to the request, so the consent screen names it and userinfo
 * releases it; the claims asked for in the id_token are kept with the code.
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

        $claimsRequest = ClaimsRequestData::fromParameter($this->getQueryStringParameter('claims', $request));
        $this->addImpliedScopes($authorizationRequest, $claimsRequest);

        $nonce = $this->getQueryStringParameter('nonce', $request);
        $isOpenIdRequest = $this->hasOpenIdScope($authorizationRequest->getScopes());

        if ($isOpenIdRequest && $nonce === null && (bool) config('oidc.require_nonce')) {
            throw $this->redirectedInvalidRequest($authorizationRequest, 'A `nonce` is required for OpenID Connect requests.');
        }

        $this->pendingAuthorizationService->remember(
            $authorizationRequest->getClient()->getIdentifier(),
            $isOpenIdRequest ? $nonce : null,
            is_string($this->getQueryStringParameter('acr_values', $request)) || $claimsRequest->isAcrRequested(),
            $claimsRequest->idTokenClaims,
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
        $authCode = Passport::authCode()->newQuery()->whereKey($authCodeId)->first([
            'nonce',
            'auth_time',
            'oidc_session_id',
            'authentication_methods',
            'is_acr_requested',
            'id_token_claims',
        ]);

        return new AuthorizationContextData(
            nonce: $authCode?->getAttribute('nonce'),
            authTime: $authCode?->getAttribute('auth_time') === null ? null : (int) $authCode->getAttribute('auth_time'),
            oidcSessionId: $authCode?->getAttribute('oidc_session_id'),
            authenticationMethods: $this->stringListFrom($authCode?->getAttribute('authentication_methods')),
            isAcrRequested: (bool) $authCode?->getAttribute('is_acr_requested'),
            idTokenClaims: $this->stringListFrom($authCode?->getAttribute('id_token_claims')),
        );
    }

    /**
     * Add the scope of every claim the `claims` parameter asks for, when this provider
     * knows that scope and the request does not carry it yet.
     */
    private function addImpliedScopes(AuthorizationRequestInterface $authorizationRequest, ClaimsRequestData $claimsRequest): void
    {
        $scopes = $authorizationRequest->getScopes();
        $scopeIdentifiers = array_map(fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(), $scopes);

        foreach ($claimsRequest->impliedScopes() as $impliedScope) {
            $scopeEntity = $this->scopeRepository->getScopeEntityByIdentifier($impliedScope);

            if ($scopeEntity !== null && ! in_array($impliedScope, $scopeIdentifiers, true)) {
                $scopes[] = $scopeEntity;
            }
        }

        $authorizationRequest->setScopes($scopes);
    }

    /**
     * A JSON list of strings stored on the code (`amr` values, requested claim names).
     *
     * @return list<string>
     */
    private function stringListFrom(mixed $storedList): array
    {
        if (is_string($storedList) && $storedList !== '') {
            $decodedList = json_decode($storedList, true);
            $storedList = is_array($decodedList) ? $decodedList : [];
        }

        if (! is_array($storedList)) {
            return [];
        }

        $strings = [];

        foreach ($storedList as $storedValue) {
            if (is_string($storedValue) && $storedValue !== '') {
                $strings[] = $storedValue;
            }
        }

        return $strings;
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
