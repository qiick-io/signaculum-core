<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuthenticationContextClassEnum;
use App\Enums\AuthenticationMethodEnum;
use Laravel\Passkeys\Passkey;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * Records how the user authenticated in this browser session (RFC 8176 `amr`).
 *
 * Values live in the session until they are copied onto the authorization code and
 * into the `id_token`. `PendingAuthorizationService` still owns `nonce` / `auth_time`.
 */
final class AuthenticationContextService
{
    public const string SESSION_KEY = 'oidc.authentication_methods';

    /**
     * @var list<string>
     */
    private const array HARDWARE_TRANSPORTS = [
        PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORT_USB,
        PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORT_NFC,
        PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORT_BLE,
        PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORT_SMART_CARD,
        PublicKeyCredentialDescriptor::AUTHENTICATOR_TRANSPORT_CABLE,
    ];

    /**
     * Append an authentication method. When two concrete factors are present, also
     * append `mfa` (RFC 8176).
     */
    public function record(AuthenticationMethodEnum $authenticationMethod): void
    {
        if (! request()->hasSession()) {
            return;
        }

        $authenticationMethods = $this->methods();

        if (! in_array($authenticationMethod->value, $authenticationMethods, true)) {
            $authenticationMethods[] = $authenticationMethod->value;
        }

        $factorCount = count(array_filter(
            $authenticationMethods,
            fn (string $method): bool => AuthenticationMethodEnum::tryFrom($method)?->isFactor() ?? false,
        ));

        if ($factorCount >= 2 && ! in_array(AuthenticationMethodEnum::MultipleFactors->value, $authenticationMethods, true)) {
            $authenticationMethods[] = AuthenticationMethodEnum::MultipleFactors->value;
        }

        request()->session()->put(self::SESSION_KEY, $authenticationMethods);
    }

    /**
     * Map a verified passkey to `hwk` or `swk` from its credential transports (RFC 8176).
     *
     * External authenticators (USB / NFC / BLE / smart-card) are hardware keys; platform
     * and hybrid authenticators are software keys when no hardware transport is present.
     */
    public function recordPasskey(Passkey $passkey): void
    {
        $this->record($this->methodForPasskey($passkey));
    }

    /**
     * Clear recorded methods (logout / new authentication without prior factors).
     */
    public function reset(): void
    {
        if (request()->hasSession()) {
            request()->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Methods recorded for this browser session.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        if (! request()->hasSession()) {
            return [];
        }

        /** @var mixed $storedMethods */
        $storedMethods = request()->session()->get(self::SESSION_KEY, []);

        if (! is_array($storedMethods)) {
            return [];
        }

        $authenticationMethods = [];

        foreach ($storedMethods as $storedMethod) {
            if (is_string($storedMethod) && AuthenticationMethodEnum::tryFrom($storedMethod) !== null) {
                $authenticationMethods[] = $storedMethod;
            }
        }

        return $authenticationMethods;
    }

    /**
     * Values for the `amr` claim: recorded methods, or `pwd` when none were recorded
     * (signed-in session without going through the login listeners, e.g. `actingAs`).
     *
     * @return list<string>
     */
    public function amrValues(): array
    {
        $authenticationMethods = $this->methods();

        if ($authenticationMethods === []) {
            return [AuthenticationMethodEnum::Password->value];
        }

        return $authenticationMethods;
    }

    /**
     * `acr` value for the given (or current) methods when the client requested one (OIDC Core §2).
     *
     * MFA (`urn:qiick:loa:mfa`) when `amr` includes `mfa`; otherwise password-only.
     *
     * @param  list<string>  $authenticationMethods
     */
    public function authenticationContextClass(array $authenticationMethods = []): AuthenticationContextClassEnum
    {
        $methods = $authenticationMethods !== [] ? $authenticationMethods : $this->amrValues();

        if (in_array(AuthenticationMethodEnum::MultipleFactors->value, $methods, true)) {
            return AuthenticationContextClassEnum::MultipleFactors;
        }

        return AuthenticationContextClassEnum::Password;
    }

    private function methodForPasskey(Passkey $passkey): AuthenticationMethodEnum
    {
        /** @var mixed $transports */
        $transports = $passkey->credential['transports'] ?? [];

        if (! is_array($transports)) {
            return AuthenticationMethodEnum::SoftwareKey;
        }

        foreach ($transports as $transport) {
            if (is_string($transport) && in_array($transport, self::HARDWARE_TRANSPORTS, true)) {
                return AuthenticationMethodEnum::HardwareKey;
            }
        }

        return AuthenticationMethodEnum::SoftwareKey;
    }
}
