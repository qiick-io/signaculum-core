<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\AcceptOrganizationInvitationAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Guest-facing accept page and POST for organisation invitations.
 */
final class AcceptInvitationController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        AcceptOrganizationInvitationAction $acceptOrganizationInvitationAction,
    ): Response|RedirectResponse {
        $invitation = $acceptOrganizationInvitationAction->findPendingOrFail($token);
        $organization = $invitation->organization()->firstOrFail();

        if ($request->isMethod('post')) {
            if (! Auth::check()) {
                $request->session()->put(
                    'url.intended',
                    route('invitations.accept', ['token' => $token]),
                );

                return to_route('login');
            }

            /** @var User $user */
            $user = $request->user();
            $acceptOrganizationInvitationAction->execute($token, $user);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted.')]);

            return to_route('organizations.show', $organization);
        }

        /** @var User|null $user */
        $user = $request->user();
        $canAccept = $user !== null
            && Str::lower($user->email) === Str::lower($invitation->email);

        return Inertia::render('auth/accept-invitation', [
            'token' => $token,
            'email' => $invitation->email,
            'roles' => $invitation->roles,
            'organization' => [
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'expiresAt' => $invitation->expires_at->toIso8601String(),
            'canAccept' => $canAccept,
            'isSignedIn' => $user !== null,
        ]);
    }
}
