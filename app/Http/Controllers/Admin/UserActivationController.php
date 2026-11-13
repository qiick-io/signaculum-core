<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\DeactivateUserAction;
use App\Actions\ReactivateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserActivationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin → Users: activate or deactivate an account (tokens + back-channel on deactivate).
 */
final class UserActivationController extends Controller
{
    public function update(
        UpdateUserActivationRequest $request,
        User $user,
        DeactivateUserAction $deactivateUserAction,
        ReactivateUserAction $reactivateUserAction,
    ): RedirectResponse {
        if ($request->shouldActivate()) {
            $reactivateUserAction->execute($user);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('User reactivated.')]);
        } else {
            $deactivateUserAction->execute($user);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('User deactivated.')]);
        }

        return to_route('admin.users.index');
    }
}
