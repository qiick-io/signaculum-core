<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateInstanceSecuritySettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Authentication & security: password policy, login attempts per minute and
 * required two-factor authentication. Excubia's SSO section is left out: Signaculum is the
 * identity provider and has no upstream IdP in v1. Instance admins only (SettingPolicy).
 */
final class InstanceSecuritySettingsController extends Controller
{
    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/instance/security', [
            'instance' => $this->settings->forEditOnly(['password_policy', 'login_max_attempts', 'two_factor_required']),
            'passwordPolicies' => SettingsRepositoryService::PASSWORD_POLICIES,
        ]);
    }

    public function update(UpdateInstanceSecuritySettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.security.edit');
    }
}
