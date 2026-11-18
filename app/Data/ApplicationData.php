<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated payload for creating or updating an OIDC application.
 */
final readonly class ApplicationData
{
    /**
     * @param  list<string>  $redirectUris
     */
    public function __construct(
        public string $name,
        public array $redirectUris,
        public bool $isConfidential,
        public bool $isFirstParty,
        public bool $isClientCredentialsEnabled = false,
        public bool $isDeviceAuthorizationEnabled = false,
        public bool $isPushedAuthorizationRequired = false,
    ) {}

    /**
     * Passport `grant_types` for this application.
     *
     * Authorization code (+ refresh) is the interactive SSO path. `client_credentials`
     * is opt-in for confidential machine clients only (OAuth 2.0 §4.4). Device
     * authorization (RFC 8628) is opt-in for CLI / TV / IoT clients.
     *
     * @return list<string>
     */
    public function grantTypes(): array
    {
        $grantTypes = ['authorization_code', 'refresh_token'];

        if ($this->isConfidential && $this->isClientCredentialsEnabled) {
            $grantTypes[] = 'client_credentials';
        }

        if ($this->isDeviceAuthorizationEnabled) {
            $grantTypes[] = 'urn:ietf:params:oauth:grant-type:device_code';
        }

        return $grantTypes;
    }
}
