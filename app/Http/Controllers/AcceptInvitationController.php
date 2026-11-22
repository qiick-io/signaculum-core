<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptOrganizationInvitationAction;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest/auth accept page for an organisation invitation token.
 */
final class AcceptInvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = OrganizationInvitation::query()
            ->with('organization:id,name,slug')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($invitation === null || $invitation->accepted_at !== null) {
            abort(404);
        }

        if ($invitation->expires_at->lte(Date::now())) {
            return Inertia::render('auth/accept-invitation', [
                'status' => 'expired',
                'organizationName' => $invitation->organization?->name,
                'email' => $invitation->email,
                'token' => $token,
            ]);
        }

        if ($request->user() === null) {
            $request->session()->put('url.intended', $request->fullUrl());

            return Inertia::render('auth/accept-invitation', [
                'status' => 'guest',
                'organizationName' => $invitation->organization?->name,
                'email' => $invitation->email,
                'token' => $token,
            ]);
        }

        return Inertia::render('auth/accept-invitation', [
            'status' => 'ready',
            'organizationName' => $invitation->organization?->name,
            'email' => $invitation->email,
            'token' => $token,
        ]);
    }

    public function store(
        Request $request,
        string $token,
        AcceptOrganizationInvitationAction $acceptOrganizationInvitationAction,
    ): RedirectResponse {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            $request->session()->put('url.intended', route('invitations.accept', ['token' => $token]));

            return redirect()->route('login');
        }

        try {
            $invitation = $acceptOrganizationInvitationAction->execute($token, $user);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You joined the organisation.')]);

        return to_route('organizations.show', $invitation->organization);
    }
}
