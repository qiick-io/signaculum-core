<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Instance admin begins a time-boxed session as another (non-admin) user.
 *
 * @side-effect Switches the web session to the target; writes an auth activity log entry.
 *
 * @throws ValidationException When already impersonating or the policy refuses.
 */
final class StartUserImpersonationAction
{
    public function __construct(
        private readonly ImpersonationService $impersonationService,
    ) {}

    public function execute(Request $request, User $actor, User $target): void
    {
        if ($this->impersonationService->isImpersonating($request)) {
            throw ValidationException::withMessages([
                'user' => __('Stop the current impersonation before starting another.'),
            ]);
        }

        Gate::forUser($actor)->authorize('impersonate', $target);

        $this->impersonationService->start($request, $actor, $target);

        activity('auth')
            ->causedBy($actor)
            ->performedOn($target)
            ->event(ActivityEventEnum::UserImpersonationStarted->value)
            ->withProperties([
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'meta' => [
                    'actor_id' => $actor->id,
                    'target_id' => $target->id,
                    'expires_at' => $this->impersonationService->expiresAt($request)?->toIso8601String(),
                ],
            ])
            ->log(ActivityEventEnum::UserImpersonationStarted->value);
    }
}
