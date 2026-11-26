<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\PrincipalDirectory;

/**
 * The user's principal in an organisation, created on joining it, through qiick/laravel-access'
 * {@see PrincipalDirectory::forMember()}: idempotent, and a principal offboarded by an earlier
 * departure is reactivated while the account is active. For a deactivated account the principal
 * is deactivated as `admin`, and stays so until the user is reactivated ({@see ReactivateUserAction}).
 */
final class EnsureOrganizationMembershipPrincipalAction
{
    public function __construct(
        private readonly PrincipalDirectory $principalDirectory,
    ) {}

    public function execute(Organization $organization, User $user): Principal
    {
        return DB::transaction(function () use ($organization, $user): Principal {
            // The account state is read under the user-row lock that deactivation and reactivation
            // take, never from the caller's copy: a deactivation committed after the member was
            // loaded still counts.
            $isAccountActive = (bool) User::query()->whereKey($user->getKey())->lockForUpdate()->value('is_active');
            $principal = $this->principalDirectory->forMember($user, (string) $organization->getKey());

            // A deactivated account's new membership stays inactive until the account is
            // reactivated, with the reason ReactivateUserAction undoes.
            if (! $isAccountActive) {
                $this->principalDirectory->deactivate($principal, DeactivationReason::Admin);
            }

            return Principal::query()->whereKey($principal->getKey())->firstOrFail();
        });
    }
}
