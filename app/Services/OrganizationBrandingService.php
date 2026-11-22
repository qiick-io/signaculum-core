<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves organisation branding for login/consent when an org context is known.
 *
 * Context comes from `organization` (slug) or a non-email `login_hint`, then stays
 * in the session for the rest of the browser auth flow. Missing org fields fall
 * back to the instance Appearance settings.
 */
final class OrganizationBrandingService
{
    public const SESSION_SLUG_KEY = 'organization_branding_slug';

    /**
     * @var array{logo: string, logo-dark: string}
     */
    public const BRANDING_ASSETS = [
        'logo' => 'logo_path',
        'logo-dark' => 'logo_dark_path',
    ];

    public function __construct(
        private readonly SettingsRepositoryService $settingsRepositoryService,
        private readonly ThemePresetService $themePresetService,
    ) {}

    /**
     * Capture an org slug from the query string into the session when it exists.
     */
    public function captureFromRequest(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $slug = $request->query('organization');

        if (! is_string($slug) || $slug === '') {
            $loginHint = $request->query('login_hint');

            if (is_string($loginHint) && $loginHint !== '' && ! str_contains($loginHint, '@')) {
                $slug = $loginHint;
            }
        }

        if (! is_string($slug) || $slug === '') {
            return;
        }

        if (Organization::query()->where('slug', $slug)->exists()) {
            $request->session()->put(self::SESSION_SLUG_KEY, $slug);
        }
    }

    /**
     * Organisation whose branding should overlay the instance theme, if any.
     */
    public function currentOrganization(Request $request): ?Organization
    {
        if (! $request->hasSession()) {
            return null;
        }

        $slug = $request->session()->get(self::SESSION_SLUG_KEY);

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Organization::query()->where('slug', $slug)->first();
    }

    /**
     * Public URLs for the organisation logos (null when not uploaded).
     *
     * @return array{logoUrl: string|null, logoDarkUrl: string|null}
     */
    public function brandingUrls(Organization $organization): array
    {
        $url = function (string $asset) use ($organization): ?string {
            $column = self::BRANDING_ASSETS[$asset] ?? null;

            if ($column === null) {
                return null;
            }

            $path = $organization->{$column};

            if (! is_string($path) || $path === '') {
                return null;
            }

            return route('organizations.branding.show', [
                'organization' => $organization->slug,
                'asset' => $asset,
                'v' => pathinfo($path, PATHINFO_FILENAME),
            ]);
        };

        return [
            'logoUrl' => $url('logo'),
            'logoDarkUrl' => $url('logo-dark'),
        ];
    }

    /**
     * Instance appearance with organisation logo/colour overrides when present.
     *
     * @return array{
     *     themePreset: string,
     *     logoUrl: string|null,
     *     logoDarkUrl: string|null,
     *     faviconUrl: string|null,
     *     themeCss: array<string, mixed>,
     *     organizationSlug: string|null,
     *     organizationName: string|null
     * }
     */
    public function effectiveAppearance(Request $request): array
    {
        $instanceUrls = $this->settingsRepositoryService->brandingUrls();
        $organization = $this->currentOrganization($request);

        $theme = $this->organizationTheme($request, $organization) ?? $this->themePresetService->current();

        $organizationUrls = $organization instanceof Organization
            ? $this->brandingUrls($organization)
            : ['logoUrl' => null, 'logoDarkUrl' => null];

        return [
            'themePreset' => $theme['themePreset'],
            'logoUrl' => $organizationUrls['logoUrl'] ?? $instanceUrls['logoUrl'],
            'logoDarkUrl' => $organizationUrls['logoDarkUrl'] ?? $instanceUrls['logoDarkUrl'],
            'faviconUrl' => $instanceUrls['faviconUrl'],
            'themeCss' => array_intersect_key($theme, array_flip([
                'light',
                'dark',
                'brandPrimary',
                'brandPrimaryDark',
                'brandPrimaryForeground',
                'brandPrimaryDarkForeground',
            ])),
            'organizationSlug' => $organization?->slug,
            'organizationName' => $organization?->name,
        ];
    }

    /**
     * Theme with the organisation's brand colours, or null to keep the instance theme.
     *
     * The colours only apply where the organisation context belongs: the guest auth
     * screens (sign-in, two-factor, email code) and the consent screen. After sign-in
     * the account and admin areas keep the instance theme even though the slug stays
     * in the session. A colour that fails WCAG AA (4.5:1 for button text, 3:1 for the
     * focus ring) is dropped in favour of the instance theme, so an organisation can
     * never make its own sign-in unreadable.
     *
     * @return array<string, mixed>|null
     */
    private function organizationTheme(Request $request, ?Organization $organization): ?array
    {
        $isBrandedAuthScreen = $request->user() === null
            || $request->routeIs('passport.authorizations.authorize');

        if (! $organization instanceof Organization || ! $isBrandedAuthScreen) {
            return null;
        }

        $brandColor = $organization->brand_color;
        $brandColorDark = $organization->brand_color_dark;
        $hasBrandColor = is_string($brandColor) && $brandColor !== '';
        $hasBrandColorDark = is_string($brandColorDark) && $brandColorDark !== '';

        if (! $hasBrandColor && ! $hasBrandColorDark) {
            return null;
        }

        $theme = $this->themePresetService->resolve(
            $this->settingsRepositoryService->themePreset(),
            $hasBrandColor ? $brandColor : $this->settingsRepositoryService->projectColor(),
            $hasBrandColorDark ? $brandColorDark : $this->settingsRepositoryService->projectColorDark(),
        );

        return $theme['brandPassesAa'] === true ? $theme : null;
    }

    /**
     * Absolute disk path for an organisation branding asset, or null when missing.
     */
    public function absolutePath(Organization $organization, string $asset): ?string
    {
        $column = self::BRANDING_ASSETS[$asset] ?? null;

        if ($column === null) {
            return null;
        }

        $path = $organization->{$column};

        if (! is_string($path) || $path === '' || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return $path;
    }
}
