<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StopUserImpersonationAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * End an active impersonation session from the banner (any authenticated user).
 */
final class StopImpersonationController extends Controller
{
    public function __invoke(
        Request $request,
        StopUserImpersonationAction $stopUserImpersonationAction,
    ): RedirectResponse {
        $actor = $stopUserImpersonationAction->execute($request);

        if ($actor === null) {
            return redirect()->route('login');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Impersonation ended. You are :name again.', ['name' => $actor->name]),
        ]);

        return to_route('admin.users.index');
    }
}
