<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Auth\Events\Login;

/**
 * Mint a fresh OIDC `sid` on every interactive web login so the claim tracks the
 * current authentication, not a previous browser session.
 */
final class RegenerateOidcSessionOnLoginListener
{
    public function __construct(
        private readonly OidcSessionService $oidcSessionService,
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

        $this->oidcSessionService->regenerate($user);
    }
}
