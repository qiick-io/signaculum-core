<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Services\PendingAuthorizationService;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\Bridge\DeviceCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;

/**
 * Passport's device code repository, plus the OpenID Connect context (`auth_time`,
 * `sid`, `amr`) saved when the user approves so the token endpoint can put it in the
 * id_token (OIDC Core §2; RFC 8176). RFC 8628 has no `nonce`.
 */
final class OidcDeviceCodeRepository extends DeviceCodeRepository
{
    public function __construct(
        private readonly PendingAuthorizationService $pendingAuthorizationService,
    ) {}

    public function persistDeviceCode(DeviceCodeEntityInterface $deviceCodeEntity): void
    {
        if ($deviceCodeEntity->getUserIdentifier() === null) {
            parent::persistDeviceCode($deviceCodeEntity);

            return;
        }

        $attributes = [
            'user_id' => $deviceCodeEntity->getUserIdentifier(),
            'user_approved_at' => $deviceCodeEntity->getUserApproved() ? Date::now() : null,
        ];

        // Capture the approving browser session only when the user grants access.
        if ($deviceCodeEntity->getUserApproved()) {
            $authorizationContext = $this->pendingAuthorizationService->approvingSessionContext();

            $attributes['auth_time'] = $authorizationContext->authTime;
            $attributes['oidc_session_id'] = $authorizationContext->oidcSessionId;
            $attributes['authentication_methods'] = json_encode($authorizationContext->authenticationMethods);
        }

        Passport::deviceCode()->newQuery()->whereKey($deviceCodeEntity->getIdentifier())->update($attributes);
    }
}
