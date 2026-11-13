<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\InviteOrganizationMemberAction;
use App\Actions\ResendOrganizationInvitationAction;
use App\Actions\RevokeOrganizationInvitationAction;
use App\Http\Requests\Organizations\StoreOrganizationInvitationRequest;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Organisation invitations view: list, create, resend and revoke.
 */
final class OrganizationInvitationController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        $organization->load([
            'invitations' => fn ($query) => $query
                ->whereNull('accepted_at')
                ->orderByDesc('created_at'),
        ]);

        return Inertia::render('organizations/invitations', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'invitations' => $organization->invitations->map(fn (OrganizationInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'roles' => $invitation->roles,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'is_expired' => $invitation->isExpired(),
            ])->values(),
            'can' => [
                'manageMembers' => $request->user()?->can('manageMembers', $organization) ?? false,
            ],
            'view' => 'invitations',
        ]);
    }

    public function store(
        StoreOrganizationInvitationRequest $request,
        Organization $organization,
        InviteOrganizationMemberAction $inviteOrganizationMemberAction,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $inviteOrganizationMemberAction->execute(
            $organization,
            (string) $request->validated('email'),
            $request->roles(),
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('organizations.invitations.index', $organization);
    }

    public function destroy(
        Organization $organization,
        OrganizationInvitation $invitation,
        RevokeOrganizationInvitationAction $revokeOrganizationInvitationAction,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $organization);

        $revokeOrganizationInvitationAction->execute($organization, $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation revoked.')]);

        return to_route('organizations.invitations.index', $organization);
    }

    public function update(
        Request $request,
        Organization $organization,
        OrganizationInvitation $invitation,
        ResendOrganizationInvitationAction $resendOrganizationInvitationAction,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $organization);

        /** @var User $user */
        $user = $request->user();

        $resendOrganizationInvitationAction->execute($organization, $invitation, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent.')]);

        return to_route('organizations.invitations.index', $organization);
    }
}
