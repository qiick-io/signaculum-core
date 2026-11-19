<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\LdapConnectionTestResultData;
use App\Data\LdapSettingsData;
use App\Services\LdapConnectionService;
use App\Services\LdapSettingsService;
use LdapRecord\Auth\BindException;
use LdapRecord\ConnectionException;
use Throwable;

/**
 * Probes LDAP connectivity with the configured (or provided) bind DN (#77).
 *
 * Never logs or returns the bind password. UI for this action lands in #78.
 */
final class TestLdapConnectionAction
{
    public function __construct(
        private readonly LdapSettingsService $ldapSettings,
        private readonly LdapConnectionService $ldapConnection,
    ) {}

    public function execute(?LdapSettingsData $settingsData = null): LdapConnectionTestResultData
    {
        try {
            $resolved = $settingsData ?? $this->ldapSettings->connectionSettings();

            if (! $this->ldapConnection->requiresEncryptedTransport($resolved->encryption) && $resolved->isEnabled) {
                return new LdapConnectionTestResultData(false, 'TLS (LDAPS) or StartTLS is required.');
            }

            if ($resolved->hosts === [] || $resolved->baseDn === '') {
                return new LdapConnectionTestResultData(false, 'Hosts and base DN are required.');
            }

            // connect() binds as the configured service account (username/password on the connection).
            $connection = $this->ldapConnection->applyFromSettings($resolved);
            $connection->connect();

            return new LdapConnectionTestResultData(true, 'Connection successful.');
        } catch (BindException) {
            return new LdapConnectionTestResultData(false, 'Bind failed with the configured credentials.');
        } catch (ConnectionException) {
            return new LdapConnectionTestResultData(false, 'Could not connect to the LDAP server.');
        } catch (Throwable) {
            return new LdapConnectionTestResultData(false, 'LDAP connection test failed.');
        }
    }
}
