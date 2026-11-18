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
 *
 * Methods are bound to the user who proved them: a factor proven for one account (for
 * example a passkey of an inactive account, verified before the login is refused) must
 * never count for a different account that signs in later in the same browser session.
 */
final class AuthenticationContextService
{
    public const string SESSION_KEY = 'oidc.authentication_methods';

    public const string SUBJECT_SESSION_KEY = 'oidc.authentication_subject';

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
     * append `mfa` (RFC 8176). `MultipleFactors` may also be recorded directly when
     * two proofs share one `amr` value (email OTP + TOTP are both `otp`).
     *
     * Methods recorded for a different user are discarded first.
     */
    public function record(AuthenticationMethodEnum $authenticationMethod, int|string $userIdentifier): void
    {
        if (! request()->hasSession()) {
            return;
        }

        $authenticationMethods = $this->methodsFor($userIdentifier);

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

        request()->session()->put([
            self::SESSION_KEY => $authenticationMethods,
            self::SUBJECT_SESSION_KEY => (string) $userIdentifier,
        ]);
    }

    /**
     * Map a verified passkey to `hwk` or `swk` from its credential transports (RFC 8176).
     *
     * External authenticators (USB / NFC / BLE / smart-card) are hardware keys; platform
     * and hybrid authenticators are software keys when no hardware transport is present.
     */
    public function recordPasskey(Passkey $passkey, int|string $userIdentifier): void
    {
        $this->record($this->methodForPasskey($passkey), $userIdentifier);
    }

    /**
     * Clear recorded methods (logout / new authentication without prior factors).
     */
    public function reset(): void
    {
        if (request()->hasSession()) {
            request()->session()->forget([self::SESSION_KEY, self::SUBJECT_SESSION_KEY]);
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
     * Methods recorded in this browser session for the given user; empty when they were
     * proven for someone else.
     *
     * @return list<string>
     */
    public function methodsFor(int|string $userIdentifier): array
    {
        if (! request()->hasSession() || request()->session()->get(self::SUBJECT_SESSION_KEY) !== (string) $userIdentifier) {
            return [];
        }

        return $this->methods();
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

    /**
     * Whether this session's `amr` satisfies an organisation MFA requirement (#131).
     *
     * True when `amr` includes RFC 8176 `mfa`, or a passkey method (`hwk` / `swk`).
     * Passkey-only sign-in never invents `mfa` in `amr` (so `acr` stays
     * `urn:qiick:loa:pwd` unless two concrete factors were used), but the org gate
     * still accepts it because enrolment treats a passkey as the second factor.
     * Email-code-only (`otp`) and password-only (`pwd`) do not satisfy.
     */
    public function sessionSatisfiesOrganizationMfa(): bool
    {
        $authenticationMethods = $this->amrValues();

        if (in_array(AuthenticationMethodEnum::MultipleFactors->value, $authenticationMethods, true)) {
            return true;
        }

        return in_array(AuthenticationMethodEnum::HardwareKey->value, $authenticationMethods, true)
            || in_array(AuthenticationMethodEnum::SoftwareKey->value, $authenticationMethods, true);
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
