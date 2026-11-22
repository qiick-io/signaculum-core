<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\DeleteUserAction;
use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Enums\PrincipalTypeEnum;
use App\Events\InstanceAdminDemotedEvent;
use App\Models\ApiKey;
use App\Models\User;
use App\Notifications\ApiKeyCreatorDemotedNotification;
use App\Services\InstanceAdminPresenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tells the remaining instance admins about the Admin API keys a demoted admin created, so a
 * key nobody answers for any more does not go unnoticed (decision 2026-11-22).
 *
 * Demotion, a group change and deactivation leave the keys valid: the mail names each key that
 * still works and links to the console to revoke it. Deletion revokes the creator's keys
 * ({@see DeleteUserAction}); the mail then names the keys that deletion revoked.
 *
 * Side effects: queues one ApiKeyCreatorDemotedNotification per key and per other active
 * instance admin. Nothing when the user created no affected key.
 */
final class NotifyAdminsOfDemotedApiKeyCreatorListener
{
    public function __construct(
        private readonly InstanceAdminPresenceService $instanceAdminPresenceService,
    ) {}

    public function handle(InstanceAdminDemotedEvent $event): void
    {
        $isRevokedByDeletion = $event->reason === InstanceAdminDemotionReasonEnum::Deleted;
        $affectedApiKeys = $this->affectedApiKeys($event, $isRevokedByDeletion);

        if ($affectedApiKeys->isEmpty()) {
            return;
        }

        $creatorLabel = "{$event->user->name} ({$event->user->email})";
        $otherAdmins = $this->instanceAdminPresenceService->activeInstanceAdmins()
            ->reject(static fn (User $admin): bool => $admin->is($event->user));

        foreach ($affectedApiKeys as $apiKey) {
            foreach ($otherAdmins as $admin) {
                $admin->notify(new ApiKeyCreatorDemotedNotification($apiKey, $creatorLabel, $isRevokedByDeletion));
            }
        }
    }

    /**
     * Instance keys of the creator that were valid when the demotion started: still valid now,
     * or, after a deletion, revoked by it.
     *
     * @return Collection<int, ApiKey>
     */
    private function affectedApiKeys(InstanceAdminDemotedEvent $event, bool $isRevokedByDeletion): Collection
    {
        return ApiKey::query()
            ->where('created_by', $event->user->getKey())
            ->whereHas('ownerPrincipal', static fn (Builder $principals) => $principals->where('type', PrincipalTypeEnum::InstanceService))
            ->where(static fn (Builder $apiKeys) => $apiKeys
                ->where('expires_at', '>', $event->demotedAt)
                ->orWhereNull('expires_at'))
            ->when(
                $isRevokedByDeletion,
                static fn (Builder $apiKeys) => $apiKeys->where('revoked_at', '>=', $event->demotedAt),
                static fn (Builder $apiKeys) => $apiKeys->whereNull('revoked_at'),
            )
            ->orderBy('id')
            ->get();
    }
}
