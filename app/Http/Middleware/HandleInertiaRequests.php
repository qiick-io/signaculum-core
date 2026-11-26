<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\PermissionEnum;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Setting;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\OrganizationBrandingService;
use App\Services\SettingsRepositoryService;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Shares Inertia props every page needs (auth user, branding, locale, flash)
 * so React layouts stay free of per-page bootstrap fetches.
 */
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
            // Instance theme, with organisation logo/colour overrides when an org context is known.
            'projectAppearance' => fn (): array => app(OrganizationBrandingService::class)->effectiveAppearance($request),
            'auth' => (function () use ($request): array {
                $user = $request->user();
                $permissionResolver = app(EffectivePermissionResolver::class);

                return [
                    'user' => $user,
                    'permissions' => $user ? $permissionResolver->permissionsFor($user) : [],
                    'roleNames' => $user ? $permissionResolver->roleNamesFor($user) : [],
                    'isSuperAdmin' => $user ? $permissionResolver->isSuperAdmin($user) : false,
                    // Derived booleans keep existing nav props working; prefer useCan() in new UI.
                    'canManageSettings' => (bool) $user?->can('update', Setting::class),
                    'canManageUsers' => (bool) $user?->can('viewAny', User::class),
                    'canManageApplications' => (bool) $user?->can('viewAny', Client::class),
                    'canShowActivityLogs' => (bool) $user?->can('viewAny', Activity::class),
                    'canManageGroups' => $user ? $permissionResolver->hasPermission($user, PermissionEnum::CanShowGroups->value) : false,
                    'impersonation' => app(ImpersonationService::class)->sharedState($request),
                ];
            })(),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
