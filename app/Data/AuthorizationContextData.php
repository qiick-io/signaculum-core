<?php

declare(strict_types=1);

namespace App\Data;

/**
 * OpenID Connect context stored with an authorization code and copied into the id_token
 * when the code is redeemed (OIDC Core §2, §3.1.2.1; Front-/Back-Channel Logout `sid`;
 * RFC 8176 `amr`; `acr` when requested).
 */
final readonly class AuthorizationContextData
{
    /**
     * @param  list<string>  $authenticationMethods  RFC 8176 `amr` values
     * @param  bool  $isAcrRequested  client sent `acr_values` or `claims` asking for `acr`
     */
    public function __construct(
        public ?string $nonce,
        public ?int $authTime,
        public ?string $oidcSessionId = null,
        public array $authenticationMethods = [],
        public bool $isAcrRequested = false,
    ) {}
}
