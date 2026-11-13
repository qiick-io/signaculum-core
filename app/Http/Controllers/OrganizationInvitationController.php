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
 * Organisation invitation list and lifecycle (create, resend, revoke).
 */
final class OrganizationInvitationController extends Controller
{
    public function index(Request $request, Organization $organization): Response
    {
        Gate::authorize('view', $organization);

        $invitations = OrganizationInvitation::query()
            ->where('organization_id', $organization->getKey())
            ->whereNull('accepted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'email', 'roles', 'expires_at', 'created_at']);

        return Inertia::render('organizations/invitations', [
            'view' => 'invitations',
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'invitations' => $invitations->map(fn (OrganizationInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'roles' => $invitation->roles,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'is_expired' => $invitation->isExpired(),
                'created_at' => $invitation->created_at?->toIso8601String(),
            ])->values(),
            'can' => [
                'manageMembers' => $request->user()?->can('manageMembers', $organization) ?? false,
            ],
        ]);
    }

    public function store(
        StoreOrganizationInvitationRequest $request,
        Organization $organization,
        InviteOrganizationMemberAction $inviteOrganizationMemberAction,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $inviteOrganizationMemberAction->execute($organization, $request->toData(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent.')]);

        return to_route('organizations.invitations.index', $organization);
    }

    public function resend(
        Organization $organization,
        OrganizationInvitation $invitation,
        ResendOrganizationInvitationAction $resendOrganizationInvitationAction,
    ): RedirectResponse {
        Gate::authorize('manageMembers', $organization);

        $resendOrganizationInvitationAction->execute($organization, $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation resent.')]);

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
}
