<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OidcScopeEnum;

/**
 * The OpenID Provider metadata (OpenID Connect Discovery 1.0 §3).
 *
 * It advertises exactly the qiick profile: authorization code only, PKCE S256,
 * RS256 id_tokens, query response mode. Clients (`qiick/laravel-oidc`) read every
 * endpoint from here, so a value that changes here is a wire-contract change.
 */
final class DiscoveryDocumentService
{
    /**
     * @return array<string, string|bool|list<string>>
     */
    public function document(): array
    {
        $issuer = (string) config('oidc.issuer');

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oauth/authorize',
            'token_endpoint' => $issuer.'/oauth/token',
            'userinfo_endpoint' => $issuer.'/oauth/userinfo',
            'revocation_endpoint' => $issuer.'/oauth/revoke',
            'end_session_endpoint' => $issuer.'/oauth/end-session',
            'jwks_uri' => $issuer.'/.well-known/jwks.json',
            'scopes_supported' => array_map(fn (OidcScopeEnum $scope): string => $scope->value, OidcScopeEnum::cases()),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => [SigningKeyService::ALGORITHM],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'revocation_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => [
                'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'azp', 'sid', 'amr', 'acr',
                ...OidcClaimsService::SUPPORTED_USER_CLAIMS,
            ],
            'acr_values_supported' => [
                'urn:qiick:loa:pwd',
                'urn:qiick:loa:mfa',
            ],
            'claims_parameter_supported' => false,
            'request_parameter_supported' => false,
            'request_uri_parameter_supported' => false,
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
            // RFC 9207 §3: clients that see true require `iss` on authorize redirects.
            'authorization_response_iss_parameter_supported' => true,
        ];
    }
}
