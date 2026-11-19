<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Services\PendingAuthorizationService;
use Illuminate\Support\Facades\Date;
use Laravel\Passport\Bridge\DeviceCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Passport's device code repository, plus the OpenID Connect context (`auth_time`,
 * `sid`, `amr`) saved when the user approves so the token endpoint can put it in the
 * id_token (OIDC Core §2; RFC 8176). RFC 8628 has no `nonce`.
 *
 * Two invariants Passport leaves to chance are enforced in the database:
 * the first decision on a code is final, and a code yields tokens at most once.
 */
final class OidcDeviceCodeRepository extends DeviceCodeRepository
{
    public function __construct(
        private readonly PendingAuthorizationService $pendingAuthorizationService,
    ) {}

    /**
     * @throws OAuthServerException when the code was already approved or denied: a
     *                              consent screen opened in another browser before that
     *                              decision must not overturn it or swap the user.
     */
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

        $decidedRowCount = Passport::deviceCode()->newQuery()
            ->whereKey($deviceCodeEntity->getIdentifier())
            ->whereNull('user_id')
            ->update($attributes);

        if ($decidedRowCount === 0) {
            throw OAuthServerException::invalidRequest('device_code', 'The device code has already been approved or denied.');
        }
    }

    /**
     * Revoke the code after league has issued its tokens (RFC 8628 §3.5).
     *
     * @throws OAuthServerException when another exchange revoked it first, so two
     *                              concurrent polls cannot both deliver tokens. The losing
     *                              request's tokens are already stored but never sent.
     */
    public function revokeDeviceCode(string $codeId): void
    {
        $revokedRowCount = Passport::deviceCode()->newQuery()
            ->whereKey($codeId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        if ($revokedRowCount === 0) {
            throw OAuthServerException::invalidGrant();
        }
    }
}
