<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisation settings view (name, slug, delete). Writes stay on OrganizationController.
 */
final class OrganizationSettingsController extends Controller
{
    public function __invoke(Request $request, Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        return Inertia::render('organizations/settings', [
            'view' => 'settings',
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'can' => [
                'update' => $request->user()?->can('update', $organization) ?? false,
                'delete' => $request->user()?->can('delete', $organization) ?? false,
            ],
        ]);
    }
}
