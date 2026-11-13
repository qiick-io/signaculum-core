<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsInstanceSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateInstanceSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → General: the instance name, description, bug report link and activity
 * retention, as Excubia's project settings. Instance admins only (SettingPolicy).
 */
final class InstanceSettingsController extends Controller
{
    use LogsInstanceSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/instance', [
            'instance' => $this->settings->forEditOnly([
                'app_name',
                'description',
                'report_bug_url',
                'activity_retention_days',
            ]),
        ]);
    }

    public function update(UpdateInstanceSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logInstanceSettingsUpdate($request, 'general', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.edit');
    }
}
