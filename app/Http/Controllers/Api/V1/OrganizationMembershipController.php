<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddOrganizationMemberAction;
use App\Actions\RemoveOrganizationMemberAction;
use App\Actions\UpdateOrganizationMemberRolesAction;
use App\Enums\OrganizationRoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrganizationMembershipResource;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Admin REST API: organisation memberships list/add/update/remove.
 */
final class OrganizationMembershipController extends Controller
{
    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('view', $organization);

        $memberships = OrganizationMembership::query()
            ->with('user')
            ->where('organization_id', $organization->getKey())
            ->orderBy('id')
            ->get();

        return OrganizationMembershipResource::collection($memberships);
    }

    public function store(
        Request $request,
        Organization $organization,
        AddOrganizationMemberAction $addOrganizationMemberAction,
    ): JsonResponse {
        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'user_id' => ['required', 'string', 'exists:users,subject'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(OrganizationRoleEnum::values())],
        ]);

        /** @var User $member */
        $member = User::query()->where('subject', $validated['user_id'])->firstOrFail();

        /** @var list<string> $roles */
        $roles = array_values(array_unique($validated['roles']));

        $membership = $addOrganizationMemberAction->execute($organization, $member, $roles);
        $membership->load('user');

        return (new OrganizationMembershipResource($membership))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        Request $request,
        Organization $organization,
        string $userId,
        UpdateOrganizationMemberRolesAction $updateOrganizationMemberRolesAction,
    ): OrganizationMembershipResource {
        Gate::authorize('manageMembers', $organization);

        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(OrganizationRoleEnum::values())],
        ]);

        /** @var User $member */
        $member = User::query()->where('subject', $userId)->firstOrFail();

        /** @var list<string> $roles */
        $roles = array_values(array_unique($validated['roles']));

        $membership = $updateOrganizationMemberRolesAction->execute($organization, $member, $roles);
        $membership->load('user');

        return new OrganizationMembershipResource($membership);
    }

    public function destroy(
        Organization $organization,
        string $userId,
        RemoveOrganizationMemberAction $removeOrganizationMemberAction,
    ): JsonResponse {
        Gate::authorize('manageMembers', $organization);

        /** @var User $member */
        $member = User::query()->where('subject', $userId)->firstOrFail();

        $removeOrganizationMemberAction->execute($organization, $member);

        return response()->json(null, 204);
    }
}
