<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\LdapSettingsData;
use App\Enums\LdapEncryptionEnum;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Reads and writes LDAP instance settings. The bind password is encrypted with
 * Laravel's encrypter and never returned to the UI or API (#77).
 */
final class LdapSettingsService
{
    public const string SETTING_KEY = 'ldap';

    public function __construct(
        private readonly SettingsRepositoryService $settings,
    ) {}

    /**
     * Defaults: LDAPS (TLS) on port 636, sync hourly, AD/OpenLDAP-friendly attribute map.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'enabled' => false,
            'hosts' => [],
            'port' => LdapEncryptionEnum::Tls->defaultPort(),
            'encryption' => LdapEncryptionEnum::Tls->value,
            'bind_dn' => '',
            'bind_password' => null,
            'base_dn' => '',
            'user_filter' => '(objectClass=person)',
            'attribute_mail' => 'mail',
            'attribute_given_name' => 'givenName',
            'attribute_family_name' => 'sn',
            'attribute_display_name' => 'displayName',
            'attribute_username' => 'uid',
            'attribute_guid' => 'objectGUID',
            'sync_interval_minutes' => 60,
            'last_synced_at' => null,
        ];
    }

    /**
     * Stored settings over defaults. The bind password stays ciphertext (or null).
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        $stored = $this->settings->all()[self::SETTING_KEY] ?? [];

        if (! is_array($stored)) {
            $stored = [];
        }

        return array_merge(self::defaults(), $stored);
    }

    /**
     * Settings safe for Inertia / API: never includes the bind password.
     *
     * @return array{
     *     enabled: bool,
     *     hosts: list<string>,
     *     port: int,
     *     encryption: string,
     *     bind_dn: string,
     *     has_bind_password: bool,
     *     base_dn: string,
     *     user_filter: string,
     *     attribute_mail: string,
     *     attribute_given_name: string,
     *     attribute_family_name: string,
     *     attribute_display_name: string,
     *     attribute_username: string,
     *     attribute_guid: string,
     *     sync_interval_minutes: int,
     *     last_synced_at: string|null
     * }
     */
    public function forEdit(): array
    {
        $raw = $this->raw();
        $hosts = $raw['hosts'] ?? [];

        return [
            'enabled' => (bool) $raw['enabled'],
            'hosts' => is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : [],
            'port' => (int) $raw['port'],
            'encryption' => (string) $raw['encryption'],
            'bind_dn' => (string) $raw['bind_dn'],
            'has_bind_password' => is_string($raw['bind_password']) && $raw['bind_password'] !== '',
            'base_dn' => (string) $raw['base_dn'],
            'user_filter' => (string) $raw['user_filter'],
            'attribute_mail' => (string) $raw['attribute_mail'],
            'attribute_given_name' => (string) $raw['attribute_given_name'],
            'attribute_family_name' => (string) $raw['attribute_family_name'],
            'attribute_display_name' => (string) $raw['attribute_display_name'],
            'attribute_username' => (string) $raw['attribute_username'],
            'attribute_guid' => (string) $raw['attribute_guid'],
            'sync_interval_minutes' => (int) $raw['sync_interval_minutes'],
            'last_synced_at' => is_string($raw['last_synced_at'] ?? null) ? $raw['last_synced_at'] : null,
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) $this->raw()['enabled'];
    }

    public function syncIntervalMinutes(): int
    {
        return max(1, min(10080, (int) $this->raw()['sync_interval_minutes']));
    }

    public function lastSyncedAt(): ?string
    {
        $value = $this->raw()['last_synced_at'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Resolved settings for connecting and syncing. Decrypts the bind password in memory only.
     *
     * @throws InvalidArgumentException when encryption is invalid or required fields are missing
     */
    public function connectionSettings(): LdapSettingsData
    {
        $raw = $this->raw();
        $encryption = LdapEncryptionEnum::tryFrom((string) $raw['encryption']) ?? LdapEncryptionEnum::Tls;
        $hosts = $raw['hosts'] ?? [];
        $hostList = is_array($hosts) ? array_values(array_filter($hosts, static fn ($host): bool => is_string($host) && $host !== '')) : [];

        return new LdapSettingsData(
            isEnabled: (bool) $raw['enabled'],
            hosts: $hostList,
            port: (int) $raw['port'],
            encryption: $encryption,
            bindDn: (string) $raw['bind_dn'],
            bindPassword: $this->decryptedBindPassword(),
            baseDn: (string) $raw['base_dn'],
            userFilter: (string) $raw['user_filter'],
            attributeMail: (string) $raw['attribute_mail'],
            attributeGivenName: (string) $raw['attribute_given_name'],
            attributeFamilyName: (string) $raw['attribute_family_name'],
            attributeDisplayName: (string) $raw['attribute_display_name'],
            attributeUsername: (string) $raw['attribute_username'],
            attributeGuid: (string) $raw['attribute_guid'],
            syncIntervalMinutes: $this->syncIntervalMinutes(),
        );
    }

    /**
     * Persist settings. Empty bind password keeps the existing ciphertext.
     *
     * @throws InvalidArgumentException when hosts/base DN/encryption are invalid
     */
    public function save(LdapSettingsData $settingsData): void
    {
        if ($settingsData->hosts === []) {
            throw new InvalidArgumentException('At least one LDAP host is required.');
        }

        if (trim($settingsData->baseDn) === '') {
            throw new InvalidArgumentException('A base DN is required.');
        }

        if ($settingsData->encryption === LdapEncryptionEnum::None && $settingsData->isEnabled) {
            throw new InvalidArgumentException('LDAP encryption must be TLS (LDAPS) or StartTLS when the connector is enabled.');
        }

        $raw = $this->raw();
        $bindPassword = $raw['bind_password'];

        if (is_string($settingsData->bindPassword) && $settingsData->bindPassword !== '') {
            $bindPassword = Crypt::encryptString($settingsData->bindPassword);
        }

        $this->settings->save([
            self::SETTING_KEY => [
                'enabled' => $settingsData->isEnabled,
                'hosts' => $settingsData->hosts,
                'port' => $settingsData->port,
                'encryption' => $settingsData->encryption->value,
                'bind_dn' => $settingsData->bindDn,
                'bind_password' => $bindPassword,
                'base_dn' => $settingsData->baseDn,
                'user_filter' => $settingsData->userFilter,
                'attribute_mail' => $settingsData->attributeMail,
                'attribute_given_name' => $settingsData->attributeGivenName,
                'attribute_family_name' => $settingsData->attributeFamilyName,
                'attribute_display_name' => $settingsData->attributeDisplayName,
                'attribute_username' => $settingsData->attributeUsername,
                'attribute_guid' => $settingsData->attributeGuid,
                'sync_interval_minutes' => max(1, min(10080, $settingsData->syncIntervalMinutes)),
                'last_synced_at' => $raw['last_synced_at'] ?? null,
            ],
        ]);
    }

    public function markSyncedAt(string $iso8601): void
    {
        $raw = $this->raw();
        $raw['last_synced_at'] = $iso8601;
        $this->settings->save([self::SETTING_KEY => $raw]);
    }

    public function decryptedBindPassword(): ?string
    {
        $ciphertext = $this->raw()['bind_password'] ?? null;

        if (! is_string($ciphertext) || $ciphertext === '') {
            return null;
        }

        try {
            return Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            return null;
        }
    }
}
