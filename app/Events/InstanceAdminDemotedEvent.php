<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Models\User;
use App\Services\InstanceAdminDemotionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Domain event: a user who was an active instance admin no longer is, whichever path took it
 * away (direct role, group, deactivation, deletion). {@see InstanceAdminDemotionService} raises
 * it once per demotion, after the change is committed.
 *
 * `demotedAt` is when the change started, so a listener can tell what that change did (for a
 * deletion: the API keys it revoked) from what happened before.
 */
final readonly class InstanceAdminDemotedEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public InstanceAdminDemotionReasonEnum $reason,
        public CarbonImmutable $demotedAt,
    ) {}
}
