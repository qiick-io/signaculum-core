<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\UpdateOrganizationBrandingAction;
use App\Http\Requests\Organizations\UpdateOrganizationBrandingRequest;
use App\Models\Organization;
use App\Services\OrganizationBrandingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Per-organisation branding: public asset serve and settings update.
 */
final class OrganizationBrandingController extends Controller
{
    /**
     * Public logo for login/consent (same cache headers as instance branding).
     */
    public function show(
        Organization $organization,
        string $asset,
        OrganizationBrandingService $organizationBrandingService,
    ): StreamedResponse {
        abort_unless(array_key_exists($asset, OrganizationBrandingService::BRANDING_ASSETS), 404);

        $path = $organizationBrandingService->absolutePath($organization, $asset);

        abort_unless(is_string($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function update(
        UpdateOrganizationBrandingRequest $request,
        Organization $organization,
        UpdateOrganizationBrandingAction $updateOrganizationBrandingAction,
    ): RedirectResponse {
        $updateOrganizationBrandingAction->execute(
            $organization,
            $request->colours(),
            $request->uploads(),
            $request->removals(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Organisation branding saved.'),
        ]);

        return to_route('organizations.settings', $organization);
    }
}
