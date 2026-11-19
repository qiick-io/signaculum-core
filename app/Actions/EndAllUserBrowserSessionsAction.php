<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OidcSession;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sign the user out of every browser: delete all session rows, cycle the remember
 * token, and fan out back-channel logout for every open OIDC session.
 *
 * Used after a password reset or an admin-set password (#163), where no "current"
 * browser should survive. Self-service password change keeps the current browser
 * via {@see RevokeOtherBrowserSessionsAction}.
 */
final class EndAllUserBrowserSessionsAction
{
    public function __construct(
        private readonly DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
        private readonly OidcSessionService $oidcSessionService,
    ) {}

    public function execute(User $user): void
    {
        DB::connection(config('session.connection'))
            ->table((string) config('session.table'))
            ->where('user_id', $user->getKey())
            ->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();

        $openOidcSessionIds = OidcSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->pluck('oidc_session_id');

        foreach ($openOidcSessionIds as $oidcSessionId) {
            if (! is_string($oidcSessionId) || $oidcSessionId === '') {
                continue;
            }

            $this->dispatchBackChannelLogoutAction->execute($user, $oidcSessionId);
            $this->oidcSessionService->markEnded($oidcSessionId);
        }

        // Catch client sessions that never wrote an OidcSession row.
        $this->dispatchBackChannelLogoutAction->execute($user);
    }
}
