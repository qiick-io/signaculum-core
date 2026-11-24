<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalDeactivationReasonEnum;
use App\Enums\PrincipalTypeEnum;
use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Deactivates the human principal when a user leaves an organisation.
 *
 * Soft-deactivation keeps the row for audit; API keys on that principal (if any later)
 * stop with `deactivated_at`.
 */
final class DeactivateOrganizationMembershipPrincipalAction
{
    public function execute(Organization $organization, User $user): void
    {
        Principal::query()
            ->where('type', PrincipalTypeEnum::User)
            ->where('organisation_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('deactivated_at')
            ->update([
                'deactivated_at' => Date::now(),
                'deactivation_reason' => PrincipalDeactivationReasonEnum::Offboarded->value,
            ]);
    }
}
