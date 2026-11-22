<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Events\InstanceAdminDemotedEvent;
use App\Models\User;
use App\Services\Authorization\EffectivePermissionResolverService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Detects instance admins who lose that status through a change, and raises
 * {@see InstanceAdminDemotedEvent} for each of them.
 *
 * Every path that can take admin away (direct role, group edit or deletion, deactivation,
 * deletion) runs its change through {@see track()}, naming the users it may affect. Whether
 * someone is an admin depends on direct roles, group roles and the account state together, so
 * the service compares the state before and after instead of guessing from the change itself.
 *
 * Idempotent by construction: only a transition from admin to not admin raises the event, so a
 * user demoted on one path and then touched on another is reported once. A change nested in
 * another tracked change (a deletion deactivates first) is reported by the outermost one only,
 * with its reason.
 */
final class InstanceAdminDemotionService
{
    /**
     * When the outermost tracked change started; null while no change is tracked.
     */
    private ?CarbonImmutable $outerChangeStartedAt = null;

    public function __construct(
        private readonly InstanceAdminPresenceService $instanceAdminPresenceService,
        private readonly EffectivePermissionResolverService $permissionResolver,
    ) {}

    /**
     * Runs `$change` and reports every candidate who was an active instance admin before it and
     * is not after it. Nothing is reported when the change throws.
     *
     * @template TResult
     *
     * @param  iterable<int|string>  $candidateUserIds  the users the change may demote
     * @param  Closure(): TResult  $change
     * @return TResult
     */
    public function track(iterable $candidateUserIds, InstanceAdminDemotionReasonEnum $reason, Closure $change): mixed
    {
        if ($this->outerChangeStartedAt !== null) {
            return $change();
        }

        // Whole seconds, as the timestamps the change writes are stored.
        $startedAt = $this->outerChangeStartedAt = CarbonImmutable::now()->startOfSecond();
        $adminsBefore = $this->activeInstanceAdminsAmong($candidateUserIds);

        try {
            $result = $change();
        } finally {
            $this->outerChangeStartedAt = null;
        }

        $remainingAdminIds = $this->activeInstanceAdminsAmong($adminsBefore->modelKeys())->modelKeys();

        foreach ($adminsBefore as $admin) {
            if (! in_array($admin->getKey(), $remainingAdminIds, true)) {
                InstanceAdminDemotedEvent::dispatch($admin, $reason, $startedAt);
            }
        }

        return $result;
    }

    /**
     * Resolved from fresh rows and without the resolver cache: the change may have altered
     * roles, groups or the account itself.
     *
     * @param  iterable<int|string>  $candidateUserIds
     * @return Collection<int, User>
     */
    private function activeInstanceAdminsAmong(iterable $candidateUserIds): Collection
    {
        $this->permissionResolver->forget();

        return User::withTrashed()
            ->whereKey(collect($candidateUserIds)->all())
            ->get()
            ->filter(fn (User $candidate): bool => $this->instanceAdminPresenceService->isActiveInstanceAdmin($candidate));
    }
}
