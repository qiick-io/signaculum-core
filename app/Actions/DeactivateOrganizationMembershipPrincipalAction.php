<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\PrincipalTypeEnum;
use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\PrincipalDirectory;

/**
 * Deactivates the human principal as `offboarded` when a user leaves an organisation, through
 * qiick/laravel-access' {@see PrincipalDirectory::deactivate()}: the row stays for audit, the
 * principals it owns are deactivated too, and `PrincipalDeactivated` revokes their API keys
 * (RevokeDeactivatedPrincipalApiKeysListener). A principal already deactivated with the account
 * becomes `offboarded` as well. Runs in the caller's transaction with the membership delete.
 */
final class DeactivateOrganizationMembershipPrincipalAction
{
    public function __construct(
        private readonly PrincipalDirectory $principalDirectory,
    ) {}

    public function execute(Organization $organization, User $user): void
    {
        $principal = Principal::query()
            ->where('type', PrincipalTypeEnum::User->value)
            ->where('organisation_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($principal !== null) {
            $this->principalDirectory->deactivate($principal, DeactivationReason::Offboarded);

            // The package keeps an earlier reason. One deactivated with the account (`admin`) is
            // now offboarded too: the membership is gone, so reactivating the account must not
            // revive it, and rejoining (forMember()) reopens it.
            Principal::query()
                ->whereKey($principal->getKey())
                ->where('deactivation_reason', DeactivationReason::Admin->value)
                ->update(['deactivation_reason' => DeactivationReason::Offboarded->value]);
        }
    }
}
