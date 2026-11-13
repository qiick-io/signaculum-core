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
    ) {}

    /**
     * Passport `grant_types` for this application.
     *
     * Authorization code (+ refresh) is the interactive SSO path. `client_credentials`
     * is opt-in for confidential machine clients only (OAuth 2.0 §4.4).
     *
     * @return list<string>
     */
    public function grantTypes(): array
    {
        $grantTypes = ['authorization_code', 'refresh_token'];

        if ($this->isConfidential && $this->isClientCredentialsEnabled) {
            $grantTypes[] = 'client_credentials';
        }

        return $grantTypes;
    }
}
