<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Models\Client;
use App\Models\User;
use App\Services\PendingAuthorizationService;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Passport's auth code repository, plus the OpenID Connect context (`nonce`,
 * `auth_time`, `sid`, `amr`) saved with each code so the token endpoint can put it in the id_token.
 *
 * Side effects: writes a `consent_granted` activity entry (client id and scope ids only).
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
        $userId = $authCodeEntity->getUserIdentifier();
        $scopes = $authCodeEntity->getScopes();
        $scopeIds = array_values(array_map(
            static fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $scopes,
        ));

        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $userId,
            'client_id' => $clientId,
            'scopes' => json_encode($scopes),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
            'nonce' => $authorizationContext->nonce,
            'auth_time' => $authorizationContext->authTime,
            'oidc_session_id' => $authorizationContext->oidcSessionId,
            'authentication_methods' => json_encode($authorizationContext->authenticationMethods),
        ])->save();

        $user = User::query()->find($userId);
        $application = Client::query()->find($clientId);
        $activity = activity()
            ->useLog('auth')
            ->event('consent_granted')
            ->withProperties([
                'client_id' => $clientId,
                'scopes' => $scopeIds,
            ]);

        if ($user !== null) {
            $activity->causedBy($user);
        }

        if ($application !== null) {
            $activity->performedOn($application);
        }

        $activity->log('Consent granted');
    }
}
