<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\LdapEncryptionEnum;

/**
 * Instance LDAP/AD connector settings for read-only sync (#77).
 *
 * The bind password is never carried here as plaintext after save: callers pass a
 * new secret only when rotating it; an empty/null password keeps the stored ciphertext.
 */
final readonly class LdapSettingsData
{
    /**
     * @param  list<string>  $hosts
     */
    public function __construct(
        public bool $isEnabled,
        public array $hosts,
        public int $port,
        public LdapEncryptionEnum $encryption,
        public string $bindDn,
        public ?string $bindPassword,
        public string $baseDn,
        public string $userFilter,
        public string $attributeMail,
        public string $attributeGivenName,
        public string $attributeFamilyName,
        public string $attributeDisplayName,
        public string $attributeUsername,
        public string $attributeGuid,
        public int $syncIntervalMinutes,
    ) {}
}
