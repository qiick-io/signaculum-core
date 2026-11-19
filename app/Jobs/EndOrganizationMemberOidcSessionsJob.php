<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\DispatchBackChannelLogoutAction;
use App\Models\OidcSession;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * End every open OIDC session for one organisation member and fan out back-channel
 * logout (OIDC Back-Channel Logout 1.0) after the organisation turns MFA on (#174).
 *
 * Idempotent on retry: `DispatchBackChannelLogoutAction` only touches rows with
 * `ended_at` null and marks them ended. Never clears the member's Signaculum web
 * session; the authorize gate steps the acting owner up in-browser.
 */
final class EndOrganizationMemberOidcSessionsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
    ) {}

    public function handle(
        DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
        OidcSessionService $oidcSessionService,
    ): void {
        $user = User::query()->find($this->userId);

        if ($user === null) {
            return;
        }

        $openOidcSessionIds = OidcSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->pluck('oidc_session_id');

        foreach ($openOidcSessionIds as $oidcSessionId) {
            if (! is_string($oidcSessionId) || $oidcSessionId === '') {
                continue;
            }

            $dispatchBackChannelLogoutAction->execute($user, $oidcSessionId);
            $oidcSessionService->markEnded($oidcSessionId);
        }

        // Catch client sessions that never wrote an OidcSession row.
        $dispatchBackChannelLogoutAction->execute($user);
    }
}
