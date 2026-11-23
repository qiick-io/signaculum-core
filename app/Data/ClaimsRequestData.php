<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\OidcScopeEnum;
use App\Services\OidcClaimsService;

/**
 * The `claims` authorization request parameter (OIDC Core §5.5), in the limited form
 * Signaculum supports: the `userinfo` and `id_token` members, claim names this provider
 * knows (`OidcClaimsService::SUPPORTED_USER_CLAIMS`, plus `acr` for the id_token) and
 * the `essential` flag. Every known claim is released when the user has a value, so
 * essential and voluntary claims behave the same (§5.5.1). `value` / `values` are not
 * supported and ignored, as are unknown claims and anything that is not a JSON object.
 */
final readonly class ClaimsRequestData
{
    /**
     * @param  list<string>  $userinfoClaims  known user claims asked for at userinfo
     * @param  list<string>  $idTokenClaims  known user claims (and `acr`) asked for in the id_token
     */
    public function __construct(
        public array $userinfoClaims = [],
        public array $idTokenClaims = [],
    ) {}

    public static function fromParameter(mixed $claimsParameter): self
    {
        if (! is_string($claimsParameter)) {
            return new self;
        }

        $decodedClaims = json_decode($claimsParameter, true);

        if (! is_array($decodedClaims)) {
            return new self;
        }

        return new self(
            self::knownClaimNames($decodedClaims['userinfo'] ?? null, OidcClaimsService::SUPPORTED_USER_CLAIMS),
            self::knownClaimNames($decodedClaims['id_token'] ?? null, [...OidcClaimsService::SUPPORTED_USER_CLAIMS, 'acr']),
        );
    }

    /**
     * The scopes whose claims this request asks for, so the consent screen names them
     * and the access token carries them (userinfo releases claims by scope).
     *
     * @return list<string>
     */
    public function impliedScopes(): array
    {
        $impliedScopes = [];

        foreach ([...$this->userinfoClaims, ...$this->idTokenClaims] as $claimName) {
            $scope = OidcClaimsService::SCOPE_OF_USER_CLAIM[$claimName] ?? null;

            if ($scope instanceof OidcScopeEnum && ! in_array($scope->value, $impliedScopes, true)) {
                $impliedScopes[] = $scope->value;
            }
        }

        return $impliedScopes;
    }

    public function isAcrRequested(): bool
    {
        return in_array('acr', $this->idTokenClaims, true);
    }

    /**
     * @param  list<string>  $knownClaims
     * @return list<string>
     */
    private static function knownClaimNames(mixed $requestedClaims, array $knownClaims): array
    {
        if (! is_array($requestedClaims)) {
            return [];
        }

        $claimNames = [];

        foreach (array_keys($requestedClaims) as $claimName) {
            if (in_array($claimName, $knownClaims, true)) {
                $claimNames[] = $claimName;
            }
        }

        return $claimNames;
    }
}
