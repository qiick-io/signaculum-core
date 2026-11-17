<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Warns about API keys that expire in `api.key_expiry_warning_days` (7) days. Scheduled daily,
 * it picks the keys expiring between that many days and one day more from now, so each key is
 * notified once without storing that it was. Revoked keys are skipped; the owner and, when it
 * is someone else, the issuer are told, if their account is still active.
 *
 * Side effects: queues one ApiKeyExpiringNotification mail per recipient.
 */
#[Signature('signaculum:notify-expiring-api-keys')]
#[Description('Mail the owners of API keys that expire in seven days')]
final class NotifyExpiringApiKeysCommand extends Command
{
    public function handle(): int
    {
        $warningDays = max(1, (int) config('api.key_expiry_warning_days', 7));
        $windowStart = now()->addDays($warningDays);
        $notifiedKeyCount = 0;

        ApiKey::query()
            ->with(['owner', 'creator'])
            ->whereNull('revoked_at')
            ->where('expires_at', '>=', $windowStart)
            ->where('expires_at', '<', $windowStart->copy()->addDay())
            ->each(function (ApiKey $apiKey) use (&$notifiedKeyCount): void {
                $recipients = collect([$apiKey->owner, $apiKey->creator])
                    ->filter(static fn (?User $recipient): bool => $recipient !== null && $recipient->is_active)
                    ->unique(static fn (User $recipient): mixed => $recipient->getKey());

                foreach ($recipients as $recipient) {
                    $recipient->notify(new ApiKeyExpiringNotification($apiKey));
                }

                $notifiedKeyCount++;
            });

        $this->components->info("Notified about {$notifiedKeyCount} expiring API key(s).");

        return self::SUCCESS;
    }
}
