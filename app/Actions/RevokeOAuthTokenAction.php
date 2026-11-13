<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\OidcTokenLookupService;
use Laravel\Passport\Passport;

/**
 * RFC 7009 token revocation: mark the presented access or refresh token revoked,
 * and revoke its pair so neither half of the grant stays usable.
 *
 * Side effects: updates `oauth_access_tokens` and `oauth_refresh_tokens`. Unknown
 * or foreign tokens are a no-op (the endpoint still returns 200).
 */
final class RevokeOAuthTokenAction
{
    public function __construct(
        private readonly OidcTokenLookupService $oidcTokenLookupService,
    ) {}

    /**
     * @param  string  $clientId  Authenticated OAuth client id (must not be empty).
     */
    public function execute(string $clientId, string $token, ?string $tokenTypeHint = null): void
    {
        if ($clientId === '') {
            return;
        }

        $resolved = $this->oidcTokenLookupService->resolve($token, $tokenTypeHint);

        if ($resolved === null || ! hash_equals($resolved['client_id'], $clientId)) {
            return;
        }

        Passport::token()->newQuery()->whereKey($resolved['access_token_id'])->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->where('access_token_id', $resolved['access_token_id'])->update(['revoked' => true]);
    }
}
