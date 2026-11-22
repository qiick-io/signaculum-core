<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalTypeEnum;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;

/**
 * Reactivate a previously deactivated IdP account so login is allowed again.
 * Clears `deactivated_at` only on membership principals for organisations the user
 * still belongs to. Tokens and sessions stay revoked; the user must sign in fresh.
 */
final class ReactivateUserAction
{
    public function execute(User $user): void
    {
        $user->forceFill(['is_active' => true])->save();

        $organisationIds = OrganizationMembership::query()
            ->where('user_id', $user->getKey())
            ->pluck('organization_id')
            ->all();

        if ($organisationIds === []) {
            return;
        }

        Principal::query()
            ->where('type', PrincipalTypeEnum::User)
            ->where('user_id', $user->getKey())
            ->whereIn('organisation_id', $organisationIds)
            ->whereNotNull('deactivated_at')
            ->update(['deactivated_at' => null]);
    }
}
