<?php

declare(strict_types=1);

namespace App\Data;

use App\Data\Concerns\GuardsCarriedPropertiesTrait;
use App\Rules\FrontchannelLogoutUriRule;

/**
 * Validated payload for creating or updating an OIDC application.
 */
final readonly class ApplicationData
{
    use GuardsCarriedPropertiesTrait;

    /**
     * The promoted properties {@see self::fromValidated()} reads out of a validated payload.
     *
     * It checks this against the constructor by reflection, so a property added below
     * without an entry here throws instead of quietly taking its default (#186).
     *
     * @var list<string>
     */
    private const CARRIED_PROPERTIES = [
        'name',
        'redirectUris',
        'isConfidential',
        'isFirstParty',
        'isClientCredentialsEnabled',
        'isDeviceAuthorizationEnabled',
        'isPushedAuthorizationRequired',
        'frontchannelLogoutUri',
        'isFrontchannelLogoutSessionRequired',
    ];

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
        public ?string $frontchannelLogoutUri = null,
        public bool $isFrontchannelLogoutSessionRequired = false,
    ) {}

    /**
     * Builds the payload from one validated admin request: the admin form or the Admin REST API.
     *
     * Applications have two doors, and before #186 each built this object field by
     * field. A field added to the form request reached the web and never the API, with
     * no error anywhere — `is_device_authorization_enabled` (#110) lived like that.
     * Both doors construct here now, so a field reaches one only by reaching the other.
     *
     * Accepts what either door produces: redirect URIs as the form's newline-separated
     * textarea or as the API's JSON array, and booleans as submitted checkboxes or as
     * JSON booleans.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        self::guardEveryPropertyIsCarried(
            self::CARRIED_PROPERTIES,
            'both application doors — the admin form requests and the Admin REST API create '
            .'validation — and is read back from both ApplicationResource classes',
        );

        return new self(
            name: (string) ($validated['name'] ?? ''),
            redirectUris: self::parseRedirectUris($validated['redirect_uris'] ?? null),
            isConfidential: self::parseBoolean($validated['is_confidential'] ?? null),
            isFirstParty: self::parseBoolean($validated['is_first_party'] ?? null),
            isClientCredentialsEnabled: self::parseBoolean($validated['is_client_credentials_enabled'] ?? null),
            isDeviceAuthorizationEnabled: self::parseBoolean($validated['is_device_authorization_enabled'] ?? null),
            isPushedAuthorizationRequired: self::parseBoolean($validated['is_pushed_authorization_required'] ?? null),
            frontchannelLogoutUri: FrontchannelLogoutUriRule::normalize($validated['frontchannel_logout_uri'] ?? null),
            isFrontchannelLogoutSessionRequired: self::parseBoolean($validated['is_frontchannel_logout_session_required'] ?? null),
        );
    }

    /**
     * Splits the admin textarea or the API's JSON array into distinct, non-empty URIs.
     *
     * Shared by the admin form requests and the Admin REST API so a submitted list
     * means the same thing whichever door it arrives through.
     *
     * @return list<string>
     */
    public static function parseRedirectUris(mixed $value): array
    {
        $lines = is_array($value)
            ? $value
            : (preg_split('/\R+/', is_string($value) ? $value : '') ?: []);

        $uris = [];

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            $uri = trim($line);

            if ($uri === '') {
                continue;
            }

            $uris[] = $uri;
        }

        return array_values(array_unique($uris));
    }

    /**
     * Reads a flag the way both doors submit one: a JSON boolean, or a form checkbox.
     *
     * Same rule as Laravel's `Request::boolean()`, so a value means the same thing
     * whichever door it arrives through.
     */
    private static function parseBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

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
