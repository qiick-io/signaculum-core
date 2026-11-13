<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\LogoutTokenRequestData;
use DateTimeImmutable;
use InvalidArgumentException;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use LogicException;
use stdClass;

/**
 * Mint RS256 `logout_token` JWTs (OIDC Back-Channel Logout 1.0 §2.4).
 *
 * Header `typ` is `logout+jwt`. Claims include a unique `jti`, the logout `events`
 * member, `sid` and/or `sub`, and never a `nonce`.
 */
final class LogoutTokenService
{
    public const string LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout';

    public const string TOKEN_TYPE = 'logout+jwt';

    public function __construct(
        private readonly SigningKeyService $signingKeyService,
    ) {}

    public function issue(LogoutTokenRequestData $logoutTokenRequest): string
    {
        if ($logoutTokenRequest->subject === null && $logoutTokenRequest->oidcSessionId === null) {
            throw new InvalidArgumentException('A logout_token needs sub and/or sid.');
        }

        $signingKey = $this->signingKeyService->activeKey();

        $jwtConfiguration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($this->nonEmpty($signingKey->private_key)),
            InMemory::plainText($this->nonEmpty($signingKey->public_key)),
        );

        $issuedAt = new DateTimeImmutable('@'.time());
        $ttl = (int) config('oidc.logout_token_ttl');

        $tokenBuilder = $jwtConfiguration->builder(ChainedFormatter::withUnixTimestampDates())
            ->withHeader('kid', $signingKey->kid)
            ->withHeader('typ', self::TOKEN_TYPE)
            ->issuedBy($this->nonEmpty((string) config('oidc.issuer')))
            ->permittedFor($this->nonEmpty($logoutTokenRequest->clientId))
            ->issuedAt($issuedAt)
            ->expiresAt($issuedAt->modify('+'.$ttl.' seconds'))
            ->identifiedBy(bin2hex(random_bytes(16)))
            ->withClaim('events', [self::LOGOUT_EVENT => new stdClass]);

        if ($logoutTokenRequest->subject !== null) {
            $tokenBuilder = $tokenBuilder->relatedTo($this->nonEmpty($logoutTokenRequest->subject));
        }

        if ($logoutTokenRequest->oidcSessionId !== null) {
            $tokenBuilder = $tokenBuilder->withClaim('sid', $this->nonEmpty($logoutTokenRequest->oidcSessionId));
        }

        return $tokenBuilder
            ->getToken($jwtConfiguration->signer(), $jwtConfiguration->signingKey())
            ->toString();
    }

    /**
     * @return non-empty-string
     */
    private function nonEmpty(string $value): string
    {
        if ($value === '') {
            throw new LogicException('A logout_token value that must not be empty is empty.');
        }

        return $value;
    }
}
