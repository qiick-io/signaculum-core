<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\LdapSettingsService;
use App\Services\LdapUserSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued read-only LDAP user sync, driven by the scheduler (#77).
 *
 * Side effects: user create/update/deactivate; activity log; marks last_synced_at.
 */
final class SyncLdapUsersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(
        LdapSettingsService $ldapSettings,
        LdapUserSyncService $ldapUserSync,
    ): void {
        if (! $ldapSettings->isEnabled()) {
            return;
        }

        if (! $this->shouldRunNow($ldapSettings)) {
            return;
        }

        $ldapUserSync->sync();
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('LDAP user sync job failed', [
            'message' => $exception?->getMessage(),
        ]);
    }

    private function shouldRunNow(LdapSettingsService $ldapSettings): bool
    {
        $lastSyncedAt = $ldapSettings->lastSyncedAt();

        if ($lastSyncedAt === null) {
            return true;
        }

        try {
            $last = CarbonImmutable::parse($lastSyncedAt);
        } catch (Throwable) {
            return true;
        }

        return $last->addMinutes($ldapSettings->syncIntervalMinutes())->isPast();
    }
}
