<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\SetInstanceAdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateInstanceAdminRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Admin → Users: grant or revoke the instance-admin flag.
 */
final class InstanceAdminController extends Controller
{
    public function update(
        UpdateInstanceAdminRequest $request,
        User $user,
        SetInstanceAdminAction $setInstanceAdminAction,
    ): RedirectResponse {
        $setInstanceAdminAction->execute($user, $request->shouldBeInstanceAdmin(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->shouldBeInstanceAdmin()
                ? __('Instance admin granted.')
                : __('Instance admin revoked.'),
        ]);

        return to_route('admin.users.index');
    }
}
