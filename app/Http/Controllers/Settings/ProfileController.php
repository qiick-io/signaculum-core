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
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'timezones' => timezone_identifiers_list(),
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
