<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\RevokeApplicationAccessAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ConnectedApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Connected apps: applications the user authorized, with access removal.
 */
final class ConnectedApplicationController extends Controller
{
    public function index(Request $request, ConnectedApplicationService $connectedApplicationService): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/connected-applications', [
            'applications' => $connectedApplicationService->applicationsFor($user),
        ]);
    }

    public function destroy(Request $request, string $clientId, RevokeApplicationAccessAction $revokeApplicationAccessAction): RedirectResponse
    {
        // oauth_clients.id is uuid; a garbage path segment must 404 before the lookup (#197).
        if (! Str::isUuid($clientId)) {
            abort(404);
        }

        /** @var User $user */
        $user = $request->user();

        $revokeApplicationAccessAction->execute($user, $clientId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access removed.')]);

        return to_route('connected-applications.index');
    }
}
