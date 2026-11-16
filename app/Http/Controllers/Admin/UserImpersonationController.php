<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\StartUserImpersonationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartUserImpersonationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin → Users: begin impersonating a non-admin account.
 */
final class UserImpersonationController extends Controller
{
    public function store(
        StartUserImpersonationRequest $request,
        User $user,
        StartUserImpersonationAction $startUserImpersonationAction,
    ): RedirectResponse {
        $actor = $request->user();
        assert($actor instanceof User);

        $startUserImpersonationAction->execute($request, $actor, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Now viewing as :name.', ['name' => $user->name]),
        ]);

        return to_route('dashboard');
    }
}
