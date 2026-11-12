<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateInstanceSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → General: the instance name, description and bug report link, as Excubia's
 * project settings. Instance admins only (SettingPolicy).
 */
final class InstanceSettingsController extends Controller
{
    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/instance', [
            'instance' => $this->settings->forEditOnly(['app_name', 'description', 'report_bug_url']),
        ]);
    }

    public function update(UpdateInstanceSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->settings());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.edit');
    }
}
