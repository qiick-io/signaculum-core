<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Services\OrganizationBrandingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisation settings view (name/slug, branding, and delete).
 */
final class OrganizationSettingsController extends Controller
{
    public function __invoke(
        Request $request,
        Organization $organization,
        OrganizationBrandingService $organizationBrandingService,
    ): Response {
        Gate::authorize('view', $organization);

        $brandingUrls = $organizationBrandingService->brandingUrls($organization);

        return Inertia::render('organizations/settings', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'brand_color' => $organization->brand_color,
                'brand_color_dark' => $organization->brand_color_dark,
                'logo_url' => $brandingUrls['logoUrl'],
                'logo_dark_url' => $brandingUrls['logoDarkUrl'],
            ],
            'can' => [
                'update' => $request->user()?->can('update', $organization) ?? false,
                'delete' => $request->user()?->can('delete', $organization) ?? false,
            ],
            'view' => 'settings',
        ]);
    }
}
