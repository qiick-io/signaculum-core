<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\UnlockUserLoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UnlockUserLoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin → Users: clear a brute-force lockout so the account can sign in again.
 */
final class UserLockoutController extends Controller
{
    public function destroy(
        UnlockUserLoginRequest $_request,
        User $user,
        UnlockUserLoginAction $unlockUserLoginAction,
    ): RedirectResponse {
        $unlockUserLoginAction->execute($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Login lockout cleared.')]);

        return to_route('admin.users.index');
    }
}
