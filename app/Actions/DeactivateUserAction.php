<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Models\OidcSession;
use App\Models\Principal;
use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;
use App\Services\InstanceAdminDemotionService;
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
        private readonly SuperAdminGuardService $superAdminGuard,
        private readonly InstanceAdminDemotionService $instanceAdminDemotionService,
    ) {}

    /**
     * Side effects: token revocation, session row deletes, queued back-channel jobs, and
     * InstanceAdminDemotedEvent (`deactivated`) when the user was an instance admin. Their
     * Admin API keys stay valid.
     *
     * @throws ValidationException when the target is the last active super-admin
     */
    public function execute(User $user): void
    {
        if ($this->superAdminGuard->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'user' => __('Cannot deactivate the last super-admin.'),
            ]);
        }

        $this->instanceAdminDemotionService->track(
            [$user->getKey()],
            InstanceAdminDemotionReasonEnum::Deactivated,
            fn () => $this->deactivate($user),
        );
    }

    private function deactivate(User $user): void
    {
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
                ->table(config()->string('session.table'))
                ->where('user_id', $user->getKey())
                ->delete();

            $user->setRememberToken(Str::random(60));
            $user->save();
        });

        // Every open client session gets its logout token, each with its own `sid`
        // (Back-Channel Logout 1.0 §2.4), including those that never wrote an OidcSession row.
        $this->dispatchBackChannelLogoutAction->execute($user);

        OidcSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);
    }
}
