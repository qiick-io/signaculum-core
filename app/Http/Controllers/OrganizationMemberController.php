<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AddOrganizationMemberAction;
use App\Actions\RemoveOrganizationMemberAction;
use App\Actions\UpdateOrganizationMemberRolesAction;
use App\Exceptions\LastOrganizationOwnerException;
use App\Http\Requests\Organizations\StoreOrganizationMemberRequest;
use App\Http\Requests\Organizations\UpdateOrganizationMemberRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Organisation members: add, update roles, remove (never the last owner).
 */
final class OrganizationMemberController extends Controller
{
    public function store(
        StoreOrganizationMemberRequest $request,
        Organization $organization,
        AddOrganizationMemberAction $addOrganizationMemberAction,
    ): RedirectResponse {
        /** @var User $user */
        $user = User::query()->findOrFail($request->validated('user_id'));

        $addOrganizationMemberAction->execute($organization, $user, $request->roles());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member added.')]);

        return to_route('organizations.show', $organization);
    }

    public function update(
        UpdateOrganizationMemberRequest $request,
        Organization $organization,
        User $user,
        UpdateOrganizationMemberRolesAction $updateOrganizationMemberRolesAction,
    ): RedirectResponse {
        try {
            $updateOrganizationMemberRolesAction->execute($organization, $user, $request->roles());
        } catch (LastOrganizationOwnerException) {
            return back()->withErrors(['roles' => __('An organisation must keep at least one owner.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member roles updated.')]);

        return to_route('organizations.show', $organization);
    }

    public function destroy(
        Organization $organization,
        User $user,
        RemoveOrganizationMemberAction $removeOrganizationMemberAction,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $organization);

        try {
            $removeOrganizationMemberAction->execute($organization, $user);
        } catch (LastOrganizationOwnerException) {
            return back()->withErrors(['member' => __('An organisation must keep at least one owner.')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('organizations.show', $organization);
    }
}
