<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Withdraw an application's access: revoke every access and refresh token the user
 * gave it. The next login to that application asks for consent again.
 */
final class RevokeApplicationAccessAction
{
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
    }
}
