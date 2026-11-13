<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\ResetUserTwoFactorAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Admin → Users: reset two-factor authentication for an account.
 */
final class UserTwoFactorController extends Controller
{
    public function destroy(Request $request, User $user, ResetUserTwoFactorAction $resetUserTwoFactorAction): RedirectResponse
    {
        Gate::authorize('update', $user);

        $resetUserTwoFactorAction->execute($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor authentication was reset.')]);

        return to_route('admin.users.index');
    }
}
