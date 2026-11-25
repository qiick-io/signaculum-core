<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiKey;
use App\Models\User;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\Contracts\ExpiryRecipientResolver;
use Qiick\ApiKeys\ExpiryRecipient;

/**
 * Who `api-keys:notify-expiring` warns about an expiring Admin API key
 * ({@see ExpiryRecipientResolver}): the owner and the issuer while they are still active
 * instance admins, and every active instance admin when neither is, so a key nobody can act on
 * never expires unannounced (decision 2026-11-22).
 */
final class ApiKeyExpiryRecipientService implements ExpiryRecipientResolver
{
    public function __construct(
        private readonly InstanceAdminPresenceService $instanceAdminPresenceService,
    ) {}

    /**
     * @param  list<ExpiryRecipient>  $roles
     * @return iterable<User>
     */
    public function recipientsFor(ApiKeyRecord $record, array $roles): iterable
    {
        if (! $record instanceof ApiKey) {
            return [];
        }

        $candidates = [];

        if (in_array(ExpiryRecipient::Owner, $roles, true)) {
            $candidates[] = $record->owner;
        }

        if (in_array(ExpiryRecipient::Issuer, $roles, true)) {
            $candidates[] = $record->creator;
        }

        $recipients = collect($candidates)
            ->filter(fn (?User $candidate): bool => $candidate !== null && $this->instanceAdminPresenceService->isActiveInstanceAdmin($candidate))
            ->unique(static fn (User $recipient): mixed => $recipient->getKey())
            ->values();

        return $recipients->isEmpty()
            ? $this->instanceAdminPresenceService->activeInstanceAdmins()->all()
            : $recipients->all();
    }
}
