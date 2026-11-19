<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use LdapRecord\Auth\BindException;
use LdapRecord\ConnectionException;
use LdapRecord\LdapRecordException;
use RuntimeException;
use Throwable;

/**
 * Authenticates an LDAP-sourced user by binding to the directory with their DN (#187).
 *
 * Never compares against the local password hash. Fail closed when the directory is
 * unreachable so a synced account cannot keep working on a local credential the
 * directory cannot revoke.
 */
final class LdapBindAuthenticationService
{
    public function __construct(
        private readonly LdapSettingsService $ldapSettings,
        private readonly LdapConnectionService $ldapConnection,
    ) {}

    /**
     * Bind with the user's DN and the submitted password.
     *
     * @return bool true when the directory accepted the credentials
     *
     * @throws RuntimeException when the directory cannot be contacted (caller maps to a distinct login error)
     */
    public function attempt(User $user, string $password): bool
    {
        $distinguishedName = $user->ldap_dn;

        if ($distinguishedName === null || $distinguishedName === '' || $password === '') {
            return false;
        }

        try {
            $settingsData = $this->ldapSettings->connectionSettings();

            if (! $settingsData->isEnabled) {
                throw new RuntimeException('LDAP directory is not available.');
            }

            $connection = $this->ldapConnection->applyFromSettings($settingsData);
            $connection->connect();

            return $connection->auth()->attempt($distinguishedName, $password);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (BindException|ConnectionException|LdapRecordException $exception) {
            throw new RuntimeException('LDAP directory is not available.', 0, $exception);
        } catch (Throwable $exception) {
            throw new RuntimeException('LDAP directory is not available.', 0, $exception);
        }
    }
}
