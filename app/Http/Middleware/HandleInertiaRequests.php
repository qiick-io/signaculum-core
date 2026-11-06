<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $settings = app(SettingsRepositoryService::class);

        return [
            ...parent::share($request),
            'name' => $settings->appName(),
            'locale' => app()->getLocale(),
            'availableLocales' => config('i18n.available_locales'),
            'instanceSettings' => ['reportBugUrl' => $settings->reportBugUrl()],
            // The saved theme, so the Appearance live preview can restore it when leaving the page.
            'projectAppearance' => function (): array {
                $theme = app(ThemePresetService::class)->current();

                return [
                    'themePreset' => $theme['themePreset'],
                    ...app(SettingsRepositoryService::class)->brandingUrls(),
                    'themeCss' => array_intersect_key($theme, array_flip(['light', 'dark', 'brandPrimary', 'brandPrimaryDark', 'brandPrimaryForeground', 'brandPrimaryDarkForeground'])),
                ];
            },
            'auth' => [
                'user' => $request->user(),
                // Only hides UI: the server still checks every request (SettingPolicy).
                'canManageSettings' => (bool) $request->user()?->can('update', Setting::class),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
