<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsInstanceSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateInstanceSecuritySettingsRequest;
use App\Services\SecondFactorAvailabilityService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Authentication & security: granular password policy, login rate limit, lockout,
 * self-registration and required two-factor authentication. Excubia's SSO section is left out:
 * Signaculum is the identity provider and has no upstream IdP in v1. Instance admins only
 * (SettingPolicy).
 */
final class InstanceSecuritySettingsController extends Controller
{
    use LogsInstanceSettingsUpdateTrait;

    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly SecondFactorAvailabilityService $secondFactorAvailabilityService,
    ) {}

    /**
     * `unavailableSecondFactor` is set when `config/fortify.php` enables no second-factor
     * method (#169), so the admin sees which organisations' members are refused at
     * `/oauth/authorize` before one of them reports it.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/instance/security', [
            'instance' => $this->settings->forEditOnly([
                'password_min_length',
                'password_require_mixed_case',
                'password_require_numbers',
                'password_require_symbols',
                'password_reject_compromised',
                'login_max_attempts',
                'login_lockout_threshold',
                'login_lockout_minutes',
                'registration_enabled',
                'two_factor_required',
                'email_code_login_enabled',
            ]),
            'unavailableSecondFactor' => $this->secondFactorAvailabilityService->isAnyMethodEnabled() ? null : [
                'mfaRequiredOrganizationNames' => $this->secondFactorAvailabilityService->unsatisfiableOrganizationNames(),
            ],
        ]);
    }

    public function update(UpdateInstanceSecuritySettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logInstanceSettingsUpdate($request, 'security', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.security.edit');
    }
}
