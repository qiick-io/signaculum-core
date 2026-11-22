<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\DeleteUserAction;
use App\Enums\WebhookEventEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\Authorization\SuperAdminGuardService;
use App\Services\WebhookDispatcherService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Account settings → Profile: show, update, and delete the signed-in user's profile.
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => timezone_identifiers_list(),
            // Directory-linked accounts cannot self-delete (decision 2026-11-22).
            'canDeleteAccount' => $user instanceof User && ! $user->isLdapSourced(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->fill($request->validated());

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $localeChanged = $user->isDirty('locale');
        $locale = $user->locale;
        $user->save();

        app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserUpdated, [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        $redirect = to_route('profile.edit');

        if ($localeChanged && is_string($locale) && $locale !== '') {
            return $redirect->cookie('locale', $locale, 60 * 24 * 365);
        }

        return $redirect;
    }

    /**
     * Delete the user's profile.
     *
     * Deactivates (tokens / sessions / principal), revokes API keys, then soft-deletes.
     */
    public function destroy(ProfileDeleteRequest $request, DeleteUserAction $deleteUserAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isLdapSourced()) {
            throw new HttpException(
                403,
                __('This account is managed by the identity directory. Ask a directory administrator to disable or remove it.'),
            );
        }

        if (app(SuperAdminGuardService::class)->isLastActiveSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'password' => __('Cannot delete the last super-admin account.'),
            ]);
        }

        $payload = [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ];

        Auth::logout();

        $deleteUserAction->execute($user);

        app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserDeleted, $payload);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
