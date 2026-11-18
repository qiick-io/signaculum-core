<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SecondFactorUnavailableException;
use App\Models\Organization;
use App\Models\User;
use Laravel\Fortify\Features;

/**
 * Which second factors this instance can actually challenge, for the MFA gates (#169).
 *
 * The Fortify feature flags say which enrolment and login methods exist; they never say
 * whether an MFA requirement applies. A requirement is stored data (`two_factor_required`,
 * `organizations.is_mfa_required`) and holds whatever `config/fortify.php` enables. This
 * service only answers how a user can meet it:
 *
 * - A TOTP secret counts only while the TOTP feature is on: with it off, Fortify drops
 *   the TOTP challenge from login, so a step-up would come back password-only and loop.
 * - A passkey counts only while the passkeys feature is on, for the same reason.
 * - With neither on, nobody can meet a requirement, and the gates refuse with
 *   {@see SecondFactorUnavailableException} instead of passing.
 */
final class SecondFactorAvailabilityService
{
    public function isAnyMethodEnabled(): bool
    {
        return Features::canManageTwoFactorAuthentication() || Features::canManagePasskeys();
    }

    /**
     * Whether the user holds a second factor that this instance's login can challenge now.
     */
    public function hasUsableSecondFactor(User $user): bool
    {
        if (Features::canManageTwoFactorAuthentication() && $user->hasEnabledTwoFactorAuthentication()) {
            return true;
        }

        return Features::canManagePasskeys() && $user->hasPasskeysEnabled();
    }

    /**
     * Organisations whose MFA requirement no member can meet, for the operator warning.
     * Empty while any second-factor method is enabled.
     *
     * @return list<string>
     */
    public function unsatisfiableOrganizationNames(): array
    {
        if ($this->isAnyMethodEnabled()) {
            return [];
        }

        return array_values(Organization::query()
            ->where('is_mfa_required', true)
            ->orderBy('name')
            ->get(['name'])
            ->map(fn (Organization $organization): string => $organization->name)
            ->all());
    }
}
