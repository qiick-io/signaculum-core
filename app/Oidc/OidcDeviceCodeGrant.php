<?php

declare(strict_types=1);

namespace App\Oidc;

use App\Data\AuthorizationContextData;
use DateInterval;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Grant\DeviceCodeGrant;
use League\OAuth2\Server\Repositories\DeviceCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Passport's device code grant with the qiick OpenID Connect profile on top.
 *
 * After league issues the access token (RFC 8628 §3.4 / §3.5), the OpenID Connect
 * context stored on the device code at approval is handed to {@see IdTokenResponse}
 * so an `id_token` can carry truthful `auth_time` / `amr` / `sid` from the approving
 * session (OIDC Core §2, §3.1.3.6; RFC 8176). There is no `nonce` on the device
 * authorization request (RFC 8628).
 */
final class OidcDeviceCodeGrant extends DeviceCodeGrant
{
    public function __construct(
        DeviceCodeRepositoryInterface $deviceCodeRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
        DateInterval $deviceCodeTTL,
        string $verificationUri,
        int $retryInterval = 5,
    ) {
        parent::__construct(
            $deviceCodeRepository,
            $refreshTokenRepository,
            $deviceCodeTTL,
            $verificationUri,
            $retryInterval,
        );
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $deviceCodeId = (string) $this->getRequestParameter('device_code', $request);
        $responseType = parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);

        if ($responseType instanceof IdTokenResponse && $deviceCodeId !== '') {
            $responseType->setAuthorizationContext($this->storedContext($deviceCodeId));
        }

        return $responseType;
    }

    private function storedContext(string $deviceCodeId): AuthorizationContextData
    {
        $deviceCode = Passport::deviceCode()->newQuery()->whereKey($deviceCodeId)->first([
            'auth_time',
            'oidc_session_id',
            'authentication_methods',
        ]);

        return new AuthorizationContextData(
            nonce: null,
            authTime: $deviceCode?->getAttribute('auth_time') === null ? null : (int) $deviceCode->getAttribute('auth_time'),
            oidcSessionId: $deviceCode?->getAttribute('oidc_session_id'),
            authenticationMethods: $this->authenticationMethodsFrom($deviceCode?->getAttribute('authentication_methods')),
            isAcrRequested: false,
        );
    }

    /**
     * @return list<string>
     */
    private function authenticationMethodsFrom(mixed $storedMethods): array
    {
        if (is_string($storedMethods) && $storedMethods !== '') {
            $decodedMethods = json_decode($storedMethods, true);
            $storedMethods = is_array($decodedMethods) ? $decodedMethods : [];
        }

        if (! is_array($storedMethods)) {
            return [];
        }

        $authenticationMethods = [];

        foreach ($storedMethods as $storedMethod) {
            if (is_string($storedMethod) && $storedMethod !== '') {
                $authenticationMethods[] = $storedMethod;
            }
        }

        return $authenticationMethods;
    }
}
