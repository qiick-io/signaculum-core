<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ClientSession;
use App\Models\User;

/**
 * Remember that this client saw the user's browser `sid` when the code was redeemed,
 * so back-channel logout can fan out later (OIDC Back-Channel Logout 1.0 §2.4).
 */
final class RecordClientSessionAction
{
    public function execute(User $user, string $clientId, string $oidcSessionId): void
    {
        ClientSession::query()->firstOrCreate(
            [
                'user_id' => $user->getKey(),
                'client_id' => $clientId,
                'oidc_session_id' => $oidcSessionId,
            ],
            ['ended_at' => null],
        );
    }
}
