<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\IdTokenRequestData;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use LogicException;

/**
 * Mint RS256-signed id_tokens (OIDC Core §2, §3.1.3.6).
 *
 * The header carries the `kid` of the active signing key so clients pick the right JWK.
 * `aud` is the client id and `azp` repeats it; `nonce` and `auth_time` come from the
 * authorization request and are only present when known.
 */
final class IdTokenService
{
    public function __construct(
        private readonly SigningKeyService $signingKeyService,
        private readonly OidcClaimsService $oidcClaimsService,
    ) {}

    public function issue(IdTokenRequestData $idTokenRequest): string
    {
        $signingKey = $this->signingKeyService->activeKey();

        $jwtConfiguration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($this->nonEmpty($signingKey->private_key)),
            InMemory::plainText($this->nonEmpty($signingKey->public_key)),
        );

        $issuedAt = new DateTimeImmutable('@'.time());
        $userClaims = $this->oidcClaimsService->claimsFor($idTokenRequest->user, $idTokenRequest->scopes);

        $tokenBuilder = $jwtConfiguration->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $signingKey->kid)
            ->issuedBy($this->nonEmpty((string) config('oidc.issuer')))
            ->relatedTo($this->nonEmpty($idTokenRequest->user->subject))
            ->permittedFor($this->nonEmpty($idTokenRequest->clientId))
            ->issuedAt($issuedAt)
            ->expiresAt($issuedAt->modify('+'.(int) config('oidc.id_token_ttl').' seconds'))
            ->withClaim('azp', $idTokenRequest->clientId);

        if ($idTokenRequest->context->authTime !== null) {
            $tokenBuilder = $tokenBuilder->withClaim('auth_time', $idTokenRequest->context->authTime);
        }

        if ($idTokenRequest->context->nonce !== null) {
            $tokenBuilder = $tokenBuilder->withClaim('nonce', $idTokenRequest->context->nonce);
        }

        unset($userClaims['sub']);

        foreach ($userClaims as $claimName => $claimValue) {
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
