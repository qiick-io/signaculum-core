<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Data\AuthorizationContextData;
use App\Data\IdTokenRequestData;
use App\Enums\OidcScopeEnum;
use App\Models\User;
use App\Services\IdTokenService;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\ResponseTypes\BearerTokenResponse;
use SensitiveParameter;

/**
 * Token response that adds an `id_token` (OIDC Core §3.1.3.3) when the redeemed
 * authorization code or device code was granted the `openid` scope.
 *
 * League clones this response type for every token request, so the context set by
 * {@see OidcAuthCodeGrant} or {@see OidcDeviceCodeGrant} never leaks between requests.
 * Refresh token responses carry no context and therefore no id_token.
 */
final class IdTokenResponse extends BearerTokenResponse
{
    private ?AuthorizationContextData $authorizationContext = null;

    public function setAuthorizationContext(AuthorizationContextData $authorizationContext): void
    {
        $this->authorizationContext = $authorizationContext;
    }

    /**
     * @return array<string, string>
     */
    protected function getExtraParams(#[SensitiveParameter] AccessTokenEntityInterface $accessToken): array
    {
        $scopes = array_map(
            fn (ScopeEntityInterface $scope): string => $scope->getIdentifier(),
            $accessToken->getScopes(),
        );

        $userIdentifier = $accessToken->getUserIdentifier();

        if ($this->authorizationContext === null || $userIdentifier === null || ! in_array(OidcScopeEnum::OpenId->value, $scopes, true)) {
            return [];
        }

        $user = User::query()->findOrFail($userIdentifier);

        // Resolved here: league builds this object once, outside the container.
        $idToken = app(IdTokenService::class)->issue(new IdTokenRequestData(
            user: $user,
            clientId: $accessToken->getClient()->getIdentifier(),
            scopes: array_values($scopes),
            context: $this->authorizationContext,
        ));

        return ['id_token' => $idToken];
    }
}
