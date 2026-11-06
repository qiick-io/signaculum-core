<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\RevokeApplicationAccessAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ConnectedApplicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        /** @var User $user */
        $user = $request->user();

        $revokeApplicationAccessAction->execute($user, $clientId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access removed.')]);

        return to_route('connected-applications.index');
    }
}
