<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\LdapSettingsService;
use App\Services\LdapUserSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs one read-only LDAP user sync immediately (#77).
 */
#[Signature('signaculum:ldap-sync')]
#[Description('Synchronise local users from the configured LDAP/AD directory (read-only)')]
final class LdapSyncCommand extends Command
{
    public function handle(
        LdapSettingsService $ldapSettings,
        LdapUserSyncService $ldapUserSync,
    ): int {
        if (! $ldapSettings->isEnabled()) {
            $this->components->warn('LDAP sync is disabled in instance settings.');

            return self::SUCCESS;
        }

        try {
            $result = $ldapUserSync->sync();
        } catch (Throwable $exception) {
            $this->components->error('LDAP sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'LDAP sync finished: created %d, updated %d, deactivated %d, conflicts %d, skipped %d.',
            $result->created,
            $result->updated,
            $result->deactivated,
            $result->conflicts,
            $result->skipped,
        ));

        return self::SUCCESS;
    }
}
