<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\DB;

/**
 * Sign one of the user's other browsers out by deleting its session row and
 * fanning out back-channel logout for that browser's `sid`.
 *
 * ponytail: a browser with a remember-me cookie signs back in silently; cycling the
 * remember token is left to "sign out all other browsers", which affects every device.
 */
final class RevokeBrowserSessionAction
{
    public function __construct(
        private readonly OidcSessionService $oidcSessionService,
        private readonly DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
    ) {}

    public function execute(User $user, string $sessionId): void
    {
        $oidcSessionId = $this->oidcSessionService->oidcSessionIdForLaravelSession($sessionId);

        DB::connection(config('session.connection'))
            ->table((string) config('session.table'))
            ->where('user_id', $user->getKey())
            ->where('id', $sessionId)
            ->delete();

        if ($oidcSessionId !== null) {
            $this->dispatchBackChannelLogoutAction->execute($user, $oidcSessionId);
            $this->oidcSessionService->markEnded($oidcSessionId);
        }
    }
}
