<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\SaveBrandingFilesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAppearanceSettingsRequest;
use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Appearance: theme preset, optional brand colours, logos, favicon and the default
 * theme for visitors without a personal choice (the login page included).
 * Instance admins only (SettingPolicy).
 */
final class AppearanceSettingsController extends Controller
{
    public function __construct(
        private readonly SettingsRepositoryService $settings,
        private readonly ThemePresetService $themePresets,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('settings/appearance', [
            'appearance' => [
                'theme_preset' => $this->settings->themePreset(),
                'project_color' => $this->settings->projectColor(),
                'project_color_dark' => $this->settings->projectColorDark(),
                'default_appearance' => $this->settings->defaultAppearance(),
                'theme_presets' => $this->themePresets->catalog(),
                'brand_passes_aa' => $this->themePresets->current()['brandPassesAa'],
                'branding' => $this->settings->brandingUrls(),
            ],
        ]);
    }

    public function update(UpdateAppearanceSettingsRequest $request, SaveBrandingFilesAction $saveBranding): RedirectResponse
    {
        $values = $request->settings();
        $this->settings->save($values);
        $saveBranding->execute($request->brandingUploads(), $request->brandingRemovals());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return to_route('appearance.edit');
    }
}
