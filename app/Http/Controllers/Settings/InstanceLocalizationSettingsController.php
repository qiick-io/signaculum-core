<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\LogsInstanceSettingsUpdateTrait;
use App\Http\Requests\Settings\UpdateInstanceLocalizationSettingsRequest;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Localization: the default UI language for people without a personal choice,
 * the login page included. Instance admins only (SettingPolicy).
 */
final class InstanceLocalizationSettingsController extends Controller
{
    use LogsInstanceSettingsUpdateTrait;

    public function __construct(private readonly SettingsRepositoryService $settings) {}

    public function edit(): Response
    {
        return Inertia::render('settings/instance/localization', [
            'instance' => ['default_language' => $this->settings->defaultLanguage()],
            'availableLocales' => config('i18n.available_locales'),
        ]);
    }

    public function update(UpdateInstanceLocalizationSettingsRequest $request): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $this->logInstanceSettingsUpdate($request, 'localization', $values);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('instance.localization.edit');
    }
}
