<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Data\AuthorizationContextData;
use App\Data\ClaimsRequestData;
use App\Enums\OidcScopeEnum;
use App\Services\PendingAuthorizationService;
use App\Services\TokenFamilyService;
use DateInterval;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

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
 * context of that code is handed to the IdTokenResponse. Codes are single use (RFC 6749
 * §4.1.2, RFC 9700 §4.5):
 *
 * - The code is claimed atomically right before the access token is issued, inside one
 *   transaction with the whole issuance, so of two parallel exchanges exactly one wins.
 * - Every access token issued from the code carries its id (`auth_code_id`), and
 *   OidcRefreshTokenGrant passes it on to refreshed tokens.
 * - Presenting a redeemed code again means it leaked: the whole token family of that
 *   code (access tokens and their refresh tokens) is revoked before league answers
 *   `invalid_grant`. This also happens when the presenter fails client authentication
 *   or is another client: holding the code is enough, by design, since the code leaked.
 */
final class OidcAuthCodeGrant extends AuthCodeGrant
{
    /**
     * The code of the current token request; the grant handles one request at a time,
     * and respondToAccessTokenRequest() resets it.
     */
    private ?string $redeemingAuthCodeId = null;

    public function __construct(
        AuthCodeRepositoryInterface $authCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $authCodeTTL,
        private readonly PendingAuthorizationService $pendingAuthorizationService,
        private readonly TokenFamilyService $tokenFamilyService,
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

    /**
     * @throws OAuthServerException `invalid_grant` for a replayed code, after the tokens
     *                              that code issued have been revoked
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $this->redeemingAuthCodeId = $this->authCodeIdFrom($request);

        try {
            $responseType = DB::transaction(
                fn (): ResponseTypeInterface => parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL),
            );
        } catch (OAuthServerException $exception) {
            $this->revokeTokenFamilyOfRedeemedCode();

            throw $exception;
        }

        if ($responseType instanceof IdTokenResponse && $this->redeemingAuthCodeId !== null) {
            $responseType->setAuthorizationContext($this->storedContext($this->redeemingAuthCodeId));
        }

        return $responseType;
    }

    /**
     * Claims the code, then issues the access token tied to it. Runs after league has
     * validated the client, the code, the redirect URI and the PKCE verifier.
     *
     * @param  array<ScopeEntityInterface>  $scopes
     *
     * @throws OAuthServerException `invalid_grant` when another exchange claimed the code first
     */
    protected function issueAccessToken(
        DateInterval $accessTokenTTL,
        ClientEntityInterface $client,
        ?string $userIdentifier,
        array $scopes = [],
    ): AccessTokenEntityInterface {
        $claimedCodeCount = Passport::authCode()->newQuery()
            ->whereKey($this->redeemingAuthCodeId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        if ($claimedCodeCount !== 1) {
            throw OAuthServerException::invalidGrant('Authorization code has been revoked');
        }

        $accessToken = parent::issueAccessToken($accessTokenTTL, $client, $userIdentifier, $scopes);

        Passport::token()->newQuery()
            ->whereKey($accessToken->getIdentifier())
            ->update(['auth_code_id' => $this->redeemingAuthCodeId]);

        return $accessToken;
    }

    /**
     * The id of the code in the request. Only codes this server encrypted decrypt, so the
     * payload can be trusted; anything else yields null and league rejects it.
     */
    private function authCodeIdFrom(ServerRequestInterface $request): ?string
    {
        $encryptedAuthCode = $this->getRequestParameter('code', $request);

        if (! is_string($encryptedAuthCode)) {
            return null;
        }

        try {
            $authCodeId = json_decode($this->decrypt($encryptedAuthCode))->auth_code_id ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($authCodeId) ? $authCodeId : null;
    }

    /**
     * A redeemed code is revoked; the tokens descending from it carry its id. The check
     * runs under the family lock, so a refresh in flight finishes first and the tokens
     * it mints are revoked with the family.
     */
    private function revokeTokenFamilyOfRedeemedCode(): void
    {
        $authCodeId = $this->redeemingAuthCodeId;

        if ($authCodeId === null) {
            return;
        }

        $this->tokenFamilyService->revokeWhenReused(
            $authCodeId,
            fn (): bool => (bool) Passport::authCode()->newQuery()->whereKey($authCodeId)->value('revoked'),
        );
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
