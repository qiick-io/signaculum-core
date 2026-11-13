<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateOrganizationAction;
use App\Actions\DeleteOrganizationAction;
use App\Actions\UpdateOrganizationAction;
use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisations the signed-in user belongs to (or all for instance admins).
 */
final class OrganizationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Organization::class);

        /** @var User $user */
        $user = $request->user();

        $organizations = Organization::query()
            ->when(
                ! (bool) $user->is_instance_admin,
                fn ($query) => $query->whereHas(
                    'memberships',
                    fn ($membershipQuery) => $membershipQuery->where('user_id', $user->getKey()),
                ),
            )
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return Inertia::render('organizations/index', [
            'organizations' => $organizations,
        ]);
    }

    public function store(StoreOrganizationRequest $request, CreateOrganizationAction $createOrganizationAction): RedirectResponse
    {
        Gate::authorize('create', Organization::class);

        /** @var User $user */
        $user = $request->user();
        $organization = $createOrganizationAction->execute($request->toData(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organisation created.')]);

        return to_route('organizations.show', $organization);
    }

    public function show(Request $request, Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        $organization->load(['memberships.user:id,first_name,last_name,email']);

        return Inertia::render('organizations/show', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'members' => $organization->memberships->map(function (OrganizationMembership $membership): array {
                $member = $membership->user;

                return [
                    'user_id' => $membership->user_id,
                    'name' => $member === null ? '' : trim("{$member->first_name} {$member->last_name}"),
                    'email' => $member?->email,
                    'roles' => $membership->roles,
                ];
            })->values(),
            'can' => [
                'manageMembers' => $request->user()?->can('manageMembers', $organization) ?? false,
            ],
            'view' => 'members',
        ]);
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
        UpdateOrganizationAction $updateOrganizationAction,
    ): RedirectResponse {
        $updateOrganizationAction->execute($organization, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organisation updated.')]);

        return to_route('organizations.settings', $organization);
    }

    public function destroy(Organization $organization, DeleteOrganizationAction $deleteOrganizationAction): RedirectResponse
    {
        Gate::authorize('delete', $organization);

        $deleteOrganizationAction->execute($organization);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organisation deleted.')]);

        return to_route('organizations.index');
    }
}
