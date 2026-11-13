<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Withdraw an application's access: revoke every access and refresh token the user
 * gave it, and send a back-channel logout for that client. The next login asks for
 * consent again.
 */
final class RevokeApplicationAccessAction
{
    public function __construct(
        private readonly DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
    ) {}

    public function execute(User $user, string $clientId): void
    {
        DB::transaction(function () use ($user, $clientId): void {
            $accessTokenIds = Passport::token()->newQuery()
                ->where('user_id', $user->getKey())
                ->where('client_id', $clientId)
                ->pluck('id');

            Passport::token()->newQuery()->whereKey($accessTokenIds)->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $accessTokenIds)->update(['revoked' => true]);
        });

        $this->dispatchBackChannelLogoutAction->execute(
            user: $user,
            oidcSessionId: null,
            clientId: $clientId,
        );
    }
}
