<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * End an active impersonation session and restore the instance admin.
 *
 * @side-effect Restores the actor session; writes an auth activity log entry when a target was present.
 *
 * @throws ValidationException When no impersonation is active.
 */
final class StopUserImpersonationAction
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
    ) {}

    /**
     * @param  bool  $isExpired  When true, the log event is the expiry variant.
     * @return User|null The restored actor, or null when the actor could not be restored.
     */
    public function execute(Request $request, bool $isExpired = false): ?User
    {
        if (! $this->impersonationService->isImpersonating($request)) {
            throw ValidationException::withMessages([
                'impersonation' => __('No impersonation session is active.'),
            ]);
        }

        $actor = $this->impersonationService->actor($request);
        $target = $request->user();
        $event = $isExpired
            ? ActivityEventEnum::UserImpersonationExpired
            : ActivityEventEnum::UserImpersonationStopped;

        if ($actor instanceof User && $target instanceof User) {
            activity('auth')
                ->causedBy($actor)
                ->performedOn($target)
                ->event($event->value)
                ->withProperties([
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'meta' => [
                        'actor_id' => $actor->id,
                        'target_id' => $target->id,
                        'is_expired' => $isExpired,
                    ],
                ])
                ->log($event->value);
        }

        return $this->impersonationService->stop($request);
    }
}
