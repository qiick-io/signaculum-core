<?php

declare(strict_types=1);

namespace App\Data;

/**
 * OpenID Connect context stored with an authorization code and copied into the id_token
 * when the code is redeemed (OIDC Core §2, §3.1.2.1; Front-/Back-Channel Logout `sid`;
 * RFC 8176 `amr`; `acr` and user claims when requested for the id_token, OIDC Core §5.5).
 */
final readonly class AuthorizationContextData
{
    /**
     * @param  list<string>  $authenticationMethods  RFC 8176 `amr` values
     * @param  bool  $isAcrRequested  client sent `acr_values` or `claims` asking for `acr`
     * @param  list<string>  $idTokenClaims  user claims the `claims` parameter asked for in the id_token
     */
    public function __construct(
        public ?string $nonce,
        public ?int $authTime,
        public ?string $oidcSessionId = null,
        public array $authenticationMethods = [],
        public bool $isAcrRequested = false,
        public array $idTokenClaims = [],
    ) {}
}
