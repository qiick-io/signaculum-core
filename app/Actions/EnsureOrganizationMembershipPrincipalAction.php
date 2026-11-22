<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalTypeEnum;
use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;

/**
 * Creates (or reactivates) the human principal for a user in one organisation.
 *
 * One principal per membership: unique `(organisation_id, user_id)`.
 */
final class EnsureOrganizationMembershipPrincipalAction
{
    public function execute(Organization $organization, User $user): Principal
    {
        $principal = Principal::query()->firstOrNew([
            'organisation_id' => $organization->getKey(),
            'user_id' => $user->getKey(),
            'type' => PrincipalTypeEnum::User,
        ]);

        $displayName = $user->name !== '' ? $user->name : (string) $user->email;

        $principal->fill([
            'name' => $displayName,
            'deactivated_at' => null,
        ]);
        $principal->save();

        return $principal;
    }
}
