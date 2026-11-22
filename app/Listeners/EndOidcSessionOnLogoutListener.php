<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\DispatchBackChannelLogoutAction;
use App\Actions\ResolveFrontChannelLogoutUrlsAction;
use App\Models\User;
use App\Services\AuthenticationContextService;
use App\Services\OidcReauthenticationService;
use App\Services\OidcSessionService;
use Illuminate\Auth\Events\Logout;

/**
 * When the web guard logs out, fan out back-channel logout for the browser `sid`
 * and stash front-channel iframe URLs on the request for the logout response.
 *
 * Runs after the session may already be cleared, so the `sid` is read before Auth
 * tears the session down when possible, and falls back to the binding table.
 * Skips fan-out during OIDC re-authentication (`max_age` / `prompt=login`): that
 * only asks for fresh credentials and must keep the same SSO `sid`.
 * Also clears recorded RFC 8176 authentication methods for the next login.
 */
final class EndOidcSessionOnLogoutListener
{
    public function __construct(
        private readonly DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
        private readonly ResolveFrontChannelLogoutUrlsAction $resolveFrontChannelLogoutUrlsAction,
        private readonly OidcSessionService $oidcSessionService,
        private readonly OidcReauthenticationService $oidcReauthenticationService,
        private readonly AuthenticationContextService $authenticationContextService,
    ) {}

    public function handle(Logout $logout): void
    {
        if ($logout->guard !== 'web') {
            return;
        }

        if ($this->oidcReauthenticationService->isInProgress()) {
            return;
        }

        $user = $logout->user;

        if (! $user instanceof User) {
            return;
        }

        $oidcSessionId = null;

        if (request()->hasSession()) {
            $sessionValue = request()->session()->get(OidcSessionService::SESSION_KEY);

            if (is_string($sessionValue) && $sessionValue !== '') {
                $oidcSessionId = $sessionValue;
            } else {
                $oidcSessionId = $this->oidcSessionService->oidcSessionIdForLaravelSession(request()->session()->getId());
            }
        }

        // Collect before back-channel ends the client_sessions rows; stash on the
        // request so Fortify's LogoutResponse can render iframes after invalidate().
        $iframeUrls = $this->resolveFrontChannelLogoutUrlsAction->execute(
            user: $user,
            oidcSessionId: $oidcSessionId,
        );

        if ($iframeUrls !== []) {
            request()->attributes->set('oidc.frontchannel_logout_urls', $iframeUrls);
        }

        $this->dispatchBackChannelLogoutAction->execute(
            user: $user,
            oidcSessionId: $oidcSessionId,
        );

        if ($oidcSessionId !== null) {
            $this->oidcSessionService->markEnded($oidcSessionId);
        }

        $this->authenticationContextService->reset();
    }
}
