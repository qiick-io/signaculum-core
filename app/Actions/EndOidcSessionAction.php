<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\Auth;

/**
 * End a Signaculum browser session and fan out back-channel logout to its clients.
 *
 * Used by RP-initiated logout, session revoke actions, and (indirectly) the web
 * logout listener when the current browser is signed out.
 */
final class EndOidcSessionAction
{
    public function __construct(
        private readonly DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
        private readonly OidcSessionService $oidcSessionService,
    ) {}

    /**
     * @param  bool  $shouldLogoutWeb  when true and the user is the current web user, clear the local session (the Logout listener fans out)
     */
    public function execute(
        User $user,
        ?string $oidcSessionId = null,
        bool $shouldLogoutWeb = true,
        ?string $clientId = null,
    ): void {
        $resolvedOidcSessionId = $oidcSessionId;

        if ($resolvedOidcSessionId === null && request()->hasSession()) {
            $sessionValue = request()->session()->get(OidcSessionService::SESSION_KEY);
            $resolvedOidcSessionId = is_string($sessionValue) && $sessionValue !== '' ? $sessionValue : null;
        }

        $isCurrentWebUser = Auth::guard('web')->check() && Auth::guard('web')->id() === $user->getKey();

        if ($shouldLogoutWeb && $isCurrentWebUser) {
            if ($resolvedOidcSessionId !== null && request()->hasSession()) {
                request()->session()->put(OidcSessionService::SESSION_KEY, $resolvedOidcSessionId);
            }

            Auth::guard('web')->logout();

            if (request()->hasSession()) {
                request()->session()->invalidate();
                request()->session()->regenerateToken();
            }

            return;
        }

        $this->dispatchBackChannelLogoutAction->execute(
            user: $user,
            oidcSessionId: $resolvedOidcSessionId,
            clientId: $clientId,
        );

        if ($resolvedOidcSessionId !== null) {
            $this->oidcSessionService->markEnded($resolvedOidcSessionId);
        }
    }
}
