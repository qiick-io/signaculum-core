<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\DeleteUserAction;
use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Enums\PrincipalTypeEnum;
use App\Events\ApiKeyCreatorDeletedEvent;
use App\Events\InstanceAdminDemotedEvent;
use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyCreatorDemotedNotification;
use App\Services\InstanceAdminPresenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tells the remaining instance admins about the Admin API keys of a creator who no longer
 * answers for them, so such a key does not go unnoticed (decision 2026-11-22).
 *
 * - Demotion, a group change and deactivation leave the keys valid: the mail names each key
 *   that still works and links to the console to revoke it.
 * - Deletion revokes the creator's keys ({@see DeleteUserAction}): the mail names every key the
 *   deletion revoked, whether or not the creator was still an admin. The demotion a deletion
 *   may also raise sends nothing, so no key is announced twice.
 *
 * Side effects: queues one ApiKeyCreatorDemotedNotification per key and per other active
 * instance admin. Nothing when no key is affected.
 */
final class NotifyAdminsOfDemotedApiKeyCreatorListener
{
    public function __construct(
        private readonly InstanceAdminPresenceService $instanceAdminPresenceService,
    ) {}

    public function handle(InstanceAdminDemotedEvent|ApiKeyCreatorDeletedEvent $event): void
    {
        if ($event instanceof ApiKeyCreatorDeletedEvent) {
            $this->notifyOtherAdmins($event->creator, ApiKey::query()->whereKey($event->revokedApiKeyIds)->orderBy('id')->get(), isRevoked: true);

            return;
        }

        if ($event->reason === InstanceAdminDemotionReasonEnum::Deleted) {
            return;
        }

        $this->notifyOtherAdmins($event->user, $this->stillValidApiKeys($event), isRevoked: false);
    }

    /**
     * @param  Collection<int, ApiKey>  $apiKeys
     */
    private function notifyOtherAdmins(User $creator, Collection $apiKeys, bool $isRevoked): void
    {
        if ($apiKeys->isEmpty()) {
            return;
        }

        $creatorLabel = "{$creator->name} ({$creator->email})";
        $otherAdmins = $this->instanceAdminPresenceService->activeInstanceAdmins()
            ->reject(static fn (User $admin): bool => $admin->is($creator));

        foreach ($apiKeys as $apiKey) {
            foreach ($otherAdmins as $admin) {
                $admin->notify(new ApiKeyCreatorDemotedNotification($apiKey, $creatorLabel, $isRevoked));
            }
        }
    }

    /**
     * Instance keys of the demoted creator that still work.
     *
     * @return Collection<int, ApiKey>
     */
    private function stillValidApiKeys(InstanceAdminDemotedEvent $event): Collection
    {
        return ApiKey::query()
            ->where('created_by', $event->user->getKey())
            ->whereHas('ownerPrincipal', static fn (Builder $principals) => $principals->where('type', PrincipalTypeEnum::InstanceService))
            ->where('expires_at', '>', $event->demotedAt)
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->get();
    }
}
