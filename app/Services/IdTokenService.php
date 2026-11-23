<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RecordClientSessionAction;
use App\Data\IdTokenRequestData;
use App\Enums\AuthenticationMethodEnum;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use LogicException;

/**
 * Mint RS256-signed id_tokens (OIDC Core §2, §3.1.3.6).
 *
 * The header carries the `kid` of the active signing key so clients pick the right JWK.
 * `aud` is the client id and `azp` repeats it; `nonce`, `auth_time` and `sid` come from
 * the authorization request / browser session and are only present when known.
 * `amr` is always present (RFC 8176) from the recorded authentication methods.
 * `acr` is present only when the authorization request asked for it (`acr_values`
 * or `claims`), as `urn:qiick:loa:pwd` or `urn:qiick:loa:mfa`.
 *
 * User claims: an access token is always issued with the id_token, so the claims of the
 * `profile` and `email` scopes are released at userinfo only (OIDC Core §5.4). The
 * id_token carries the ones the `claims` parameter asked for there (§5.5), plus `orgs`
 * when `qiick.orgs` is granted: a qiick scope outside §5.4 that clients map roles from.
 */
final class IdTokenService
{
    public function __construct(
        private readonly SigningKeyService $signingKeyService,
        private readonly OidcClaimsService $oidcClaimsService,
        private readonly RecordClientSessionAction $recordClientSessionAction,
        private readonly AuthenticationContextService $authenticationContextService,
    ) {}

    public function issue(IdTokenRequestData $idTokenRequest): string
    {
        $signingKey = $this->signingKeyService->activeKey();

        $jwtConfiguration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($this->nonEmpty($signingKey->private_key)),
            InMemory::plainText($this->nonEmpty($signingKey->public_key)),
        );

        // Laravel's clock so tests can freezeTime(); wire still uses unix seconds via the formatter.
        $issuedAt = new DateTimeImmutable('@'.Date::now()->getTimestamp());
        $userClaims = $this->oidcClaimsService->claimsFor($idTokenRequest->user, $idTokenRequest->scopes);
        $authenticationMethods = $idTokenRequest->context->authenticationMethods;

        if ($authenticationMethods === []) {
            $authenticationMethods = [AuthenticationMethodEnum::Password->value];
        }

        $tokenBuilder = $jwtConfiguration->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $signingKey->kid)
            ->issuedBy($this->nonEmpty((string) config('oidc.issuer')))
            ->relatedTo($this->nonEmpty($idTokenRequest->user->subject))
            ->permittedFor($this->nonEmpty($idTokenRequest->clientId))
            ->issuedAt($issuedAt)
            ->expiresAt($issuedAt->modify('+'.(int) config('oidc.id_token_ttl').' seconds'))
            ->withClaim('azp', $idTokenRequest->clientId)
            ->withClaim('amr', $authenticationMethods);

        if ($idTokenRequest->context->isAcrRequested) {
            $tokenBuilder = $tokenBuilder->withClaim(
                'acr',
                $this->authenticationContextService
                    ->authenticationContextClass($authenticationMethods)
                    ->value,
            );
        }

        if ($idTokenRequest->context->authTime !== null) {
            $tokenBuilder = $tokenBuilder->withClaim('auth_time', $idTokenRequest->context->authTime);
        }

        if ($idTokenRequest->context->nonce !== null) {
            $tokenBuilder = $tokenBuilder->withClaim('nonce', $idTokenRequest->context->nonce);
        }

        if ($idTokenRequest->context->oidcSessionId !== null) {
            $tokenBuilder = $tokenBuilder->withClaim('sid', $this->nonEmpty($idTokenRequest->context->oidcSessionId));
            $this->recordClientSessionAction->execute(
                $idTokenRequest->user,
                $idTokenRequest->clientId,
                $idTokenRequest->context->oidcSessionId,
            );
        }

        unset($userClaims['sub']);

        $idTokenClaimNames = [...$idTokenRequest->context->idTokenClaims, 'orgs'];

        foreach ($userClaims as $claimName => $claimValue) {
            if (! in_array($claimName, $idTokenClaimNames, true)) {
                continue;
            }

            $tokenBuilder = $tokenBuilder->withClaim($this->nonEmpty($claimName), $claimValue);
        }

        return $tokenBuilder
            ->getToken($jwtConfiguration->signer(), $jwtConfiguration->signingKey())
            ->toString();
    }

    /**
     * lcobucci/jwt refuses empty keys, issuers, subjects and audiences.
     *
     * @return non-empty-string
     *
     * @throws LogicException when a required value is empty (misconfiguration)
     */
    private function nonEmpty(string $value): string
    {
        if ($value === '') {
            throw new LogicException('An id_token value that must not be empty is empty.');
        }

        return $value;
    }
}
