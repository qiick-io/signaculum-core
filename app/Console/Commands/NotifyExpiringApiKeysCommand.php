<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use App\Services\InstanceAdminPresenceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Warns about API keys that expire in `api.key_expiry_warning_days` (7) days. Scheduled daily,
 * it picks the keys expiring between that many days and one day more from now, so each key is
 * notified once without storing that it was. Revoked keys are skipped. The owner and, when it
 * is someone else, the issuer are told while they are still active instance admins; when
 * neither is, every active instance admin is, since any of them may rotate an Admin API key.
 *
 * Side effects: queues one ApiKeyExpiringNotification mail per recipient.
 */
#[Signature('signaculum:notify-expiring-api-keys')]
#[Description('Mail the owners of API keys that expire in seven days')]
final class NotifyExpiringApiKeysCommand extends Command
{
    public function handle(InstanceAdminPresenceService $instanceAdminPresenceService): int
    {
        $warningDays = max(1, config()->integer('api.key_expiry_warning_days'));
        $windowStart = now()->addDays($warningDays);
        $notifiedKeyCount = 0;

        ApiKey::query()
            ->with(['owner', 'creator'])
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', $windowStart)
            ->where('expires_at', '<', $windowStart->copy()->addDay())
            ->each(function (ApiKey $apiKey) use (&$notifiedKeyCount, $instanceAdminPresenceService): void {
                $recipients = collect([$apiKey->owner, $apiKey->creator])
                    ->filter(static fn (?User $recipient): bool => $recipient !== null
                        && $instanceAdminPresenceService->isActiveInstanceAdmin($recipient))
                    ->unique(static fn (User $recipient): mixed => $recipient->getKey());

                if ($recipients->isEmpty()) {
                    $recipients = $instanceAdminPresenceService->activeInstanceAdmins();
                }

                foreach ($recipients as $recipient) {
                    $recipient->notify(new ApiKeyExpiringNotification($apiKey));
                }

                $notifiedKeyCount++;
            });

        $this->components->info("Notified about {$notifiedKeyCount} expiring API key(s).");

        return self::SUCCESS;
    }
}
