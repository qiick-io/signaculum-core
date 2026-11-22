<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\OidcReauthenticationService;
use App\Services\OidcSessionService;
use Illuminate\Auth\Events\Login;

/**
 * Mint a fresh OIDC `sid` on every interactive web login so the claim tracks the
 * current authentication, not a previous browser session.
 *
 * During OIDC re-authentication (`max_age` / `prompt=login`) the previous `sid`
 * is kept so connected apps are not logged out (OIDC Core §3.1.2.1).
 */
final class RegenerateOidcSessionOnLoginListener
{
    public function __construct(
        private readonly OidcSessionService $oidcSessionService,
        private readonly OidcReauthenticationService $oidcReauthenticationService,
    ) {}

    public function handle(Login $login): void
    {
        if ($login->guard !== 'web') {
            return;
        }

        $user = $login->user;

        if (! $user instanceof User) {
            return;
        }

        if ($this->oidcReauthenticationService->isInProgress()) {
            $this->oidcReauthenticationService->finishKeepingSid($user);

            return;
        }

        $this->oidcSessionService->regenerate($user);
    }
}
