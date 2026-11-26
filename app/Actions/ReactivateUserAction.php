<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalTypeEnum;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Qiick\Access\Principals\DeactivationReason;

/**
 * Reactivate a previously deactivated IdP account so login is allowed again. Tokens and
 * sessions stay revoked; the user must sign in fresh.
 *
 * Product rule on top of qiick/laravel-access' PrincipalDirectory (which only reactivates an
 * `offboarded` principal on re-membership): reactivating the user undoes only what deactivating
 * the user did. It reactivates the membership principals deactivated as `admin`
 * ({@see DeactivateUserAction}), in organisations the user still belongs to that are not
 * deleted; a principal offboarded (left or deleted organisation), erased, expired or deactivated
 * with its owner stays inactive. The package offers no reactivation API, so the update is
 * conditional on the reason, like the directory's own.
 */
final class ReactivateUserAction
{
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // The same user-row lock as deactivation and a new membership's principal, so the
            // account and its principals change together.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $user->forceFill(['is_active' => true])->save();

            // One statement, bound to the memberships that exist when it runs: a removal committed
            // meanwhile (which also marks the principal `offboarded`) is never revived. whereHas
            // applies the soft-delete scope: a deleted organisation's principal stays offboarded.
            Principal::query()
                ->where('type', PrincipalTypeEnum::User->value)
                ->where('user_id', $user->getKey())
                ->whereIn('organisation_id', OrganizationMembership::query()
                    ->where('user_id', $user->getKey())
                    ->whereHas('organization')
                    ->select('organization_id'))
                ->whereNotNull('deactivated_at')
                ->where('deactivation_reason', DeactivationReason::Admin->value)
                ->update(['deactivated_at' => null, 'deactivation_reason' => null]);
        });
    }
}
