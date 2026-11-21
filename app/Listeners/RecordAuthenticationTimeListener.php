<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\PendingAuthorizationService;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Request;

/**
 * Store the time of every interactive login (password, two-factor, passkey, remember-me)
 * so the id_token can carry `auth_time`, and stamp `last_login_at` / `last_login_ip` on the user.
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

        $user = $login->user;

        if (! $user instanceof User) {
            return;
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => Request::ip(),
        ])->save();
    }
}
