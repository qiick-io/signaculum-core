<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\LdapSettingsData;
use App\Enums\LdapEncryptionEnum;
use LdapRecord\Connection;
use LdapRecord\Container;
use LdapRecord\Testing\ConnectionFake;
use RuntimeException;

/**
 * Applies instance LDAP settings to the LdapRecord default connection (#77).
 *
 * Side effects: mutates config('ldap.connections.default') and the Container connection.
 */
final class LdapConnectionService
{
    public function __construct(
        private readonly LdapSettingsService $ldapSettings,
    ) {}

    /**
     * @throws RuntimeException when required connection fields are missing
     */
    public function applyFromSettings(?LdapSettingsData $settingsData = null): Connection
    {
        $settingsData ??= $this->ldapSettings->connectionSettings();

        if ($settingsData->hosts === []) {
            throw new RuntimeException('LDAP hosts are not configured.');
        }

        if ($settingsData->baseDn === '') {
            throw new RuntimeException('LDAP base DN is not configured.');
        }

        $config = [
            'hosts' => $settingsData->hosts,
            'username' => $settingsData->bindDn !== '' ? $settingsData->bindDn : null,
            'password' => $settingsData->bindPassword,
            'port' => $settingsData->port,
            'base_dn' => $settingsData->baseDn,
            'timeout' => 5,
            'use_tls' => $settingsData->encryption->usesTls(),
            'use_starttls' => $settingsData->encryption->usesStartTls(),
            'use_sasl' => false,
        ];

        config([
            'ldap.default' => 'default',
            'ldap.connections.default' => $config,
            // Never log LDAP operations with bind credentials in application logs.
            'ldap.logging.enabled' => false,
        ]);

        $container = Container::getInstance();

        // Keep DirectoryEmulator / ConnectionFake in tests; never replace with a live socket.
        if ($container->hasConnection('default')) {
            $existing = $container->getConnection('default');

            if ($existing instanceof ConnectionFake) {
                $container->setDefaultConnection('default');

                return $existing;
            }
        }

        $connection = new Connection($config);
        $container->addConnection($connection, 'default');
        $container->setDefaultConnection('default');

        return $connection;
    }

    /**
     * Encryption must be TLS or StartTLS whenever the connector is enabled.
     */
    public function requiresEncryptedTransport(LdapEncryptionEnum $encryption): bool
    {
        return $encryption !== LdapEncryptionEnum::None;
    }
}
