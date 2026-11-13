<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Services\PendingAuthorizationService;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Passport's auth code repository, plus the OpenID Connect context (`nonce`,
 * `auth_time`, `sid`, `amr`) saved with each code so the token endpoint can put it in the id_token.
 */
final class OidcAuthCodeRepository extends AuthCodeRepository
{
    public function __construct(
        private readonly PendingAuthorizationService $pendingAuthorizationService,
    ) {}

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $clientId = $authCodeEntity->getClient()->getIdentifier();
        $authorizationContext = $this->pendingAuthorizationService->pull($clientId);

        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $clientId,
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
            'nonce' => $authorizationContext->nonce,
            'auth_time' => $authorizationContext->authTime,
            'oidc_session_id' => $authorizationContext->oidcSessionId,
            'authentication_methods' => json_encode($authorizationContext->authenticationMethods),
        ])->save();
    }
}
