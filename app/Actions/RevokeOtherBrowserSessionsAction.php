<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OidcSession;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sign the user out everywhere except the current browser: delete the other session
 * rows, fan out back-channel logout for each other `sid`, and cycle the remember token.
 */
final class RevokeOtherBrowserSessionsAction
{
    public function __construct(
        private readonly DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
        private readonly OidcSessionService $oidcSessionService,
    ) {}

    public function execute(User $user, string $currentSessionId): void
    {
        $otherOidcSessions = OidcSession::query()
            ->where('user_id', $user->getKey())
            ->where('laravel_session_id', '!=', $currentSessionId)
            ->whereNull('ended_at')
            ->get();

        foreach ($otherOidcSessions as $oidcSession) {
            $this->dispatchBackChannelLogoutAction->execute($user, $oidcSession->oidc_session_id);
            $this->oidcSessionService->markEnded($oidcSession->oidc_session_id);
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table'))
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
