<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\OidcSession;
use App\Models\Principal;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

/**
 * Deactivate an IdP account: refuse further login, revoke Passport tokens, clear
 * browser sessions, and fan out back-channel logout to every open client session.
 */
final class DeactivateUserAction
{
    public function __construct(
        private readonly DispatchBackChannelLogoutAction $dispatchBackChannelLogoutAction,
        private readonly OidcSessionService $oidcSessionService,
    ) {}

    /**
     * Side effects: token revocation, session row deletes, queued back-channel jobs.
     *
     * @throws ValidationException when the target is the last instance admin
     */
    public function execute(User $user): void
    {
        if ($user->is_instance_admin && $this->isLastInstanceAdmin($user)) {
            throw ValidationException::withMessages([
                'user' => __('Cannot deactivate the last instance admin.'),
            ]);
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['is_active' => false])->save();

            Principal::query()
                ->where('user_id', $user->getKey())
                ->whereNull('deactivated_at')
                ->update(['deactivated_at' => now()]);

            $accessTokenIds = Passport::token()->newQuery()
                ->where('user_id', $user->getKey())
                ->pluck('id');

            Passport::token()->newQuery()->whereKey($accessTokenIds)->update(['revoked' => true]);
            Passport::refreshToken()->newQuery()->whereIn('access_token_id', $accessTokenIds)->update(['revoked' => true]);

            DB::connection(config('session.connection'))
                ->table((string) config('session.table'))
                ->where('user_id', $user->getKey())
                ->delete();

            $user->setRememberToken(Str::random(60));
            $user->save();
        });

        $openOidcSessions = OidcSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->pluck('oidc_session_id');

        foreach ($openOidcSessions as $oidcSessionId) {
            if (! is_string($oidcSessionId) || $oidcSessionId === '') {
                continue;
            }

            $this->dispatchBackChannelLogoutAction->execute($user, $oidcSessionId);
            $this->oidcSessionService->markEnded($oidcSessionId);
        }

        // Catch client sessions that never wrote an OidcSession row.
        $this->dispatchBackChannelLogoutAction->execute($user);
    }

    private function isLastInstanceAdmin(User $user): bool
    {
        return User::query()
            ->where('is_instance_admin', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
