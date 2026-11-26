<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\User;
use App\Services\SecondFactorAvailabilityService;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Auth\Authenticatable;
use Qiick\Access\Contracts\TwoFactorPolicy;

/**
 * The instance setting "require two-factor authentication" for qiick/laravel-access'
 * `EnsureTwoFactorIsEnabled` ({@see TwoFactorPolicy}). Only a factor this instance can challenge
 * counts (config/fortify.php decides which methods are enabled, #169).
 */
final class AccessTwoFactorPolicyService implements TwoFactorPolicy
{
    public function __construct(
        private readonly SettingsRepositoryService $settingsRepositoryService,
        private readonly SecondFactorAvailabilityService $secondFactorAvailabilityService,
    ) {}

    public function isRequired(Authenticatable $user): bool
    {
        return $this->settingsRepositoryService->isTwoFactorRequired();
    }

    public function hasUsableFactor(Authenticatable $user): bool
    {
        return $user instanceof User && $this->secondFactorAvailabilityService->hasUsableSecondFactor($user);
    }

    public function isAnyMethodEnabled(): bool
    {
        return $this->secondFactorAvailabilityService->isAnyMethodEnabled();
    }
}
