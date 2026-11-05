<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\PendingAuthorizationService;
use Illuminate\Auth\Events\Login;

/**
 * Store the time of every interactive login (password, two-factor, passkey, remember-me)
 * so the id_token can carry `auth_time`.
 */
final class RecordAuthenticationTimeListener
{
    public function __construct(
        private readonly PendingAuthorizationService $pendingAuthorizationService,
    ) {}

    public function handle(Login $login): void
    {
        if ($login->guard !== 'web') {
            return;
        }

        $this->pendingAuthorizationService->recordAuthentication();
    }
}
