<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\RevokeBrowserSessionAction;
use App\Actions\RevokeOtherBrowserSessionsAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\BrowserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Sessions: the user's signed-in browsers, with sign-out per browser and
 * for all other browsers.
 */
final class BrowserSessionController extends Controller
{
    public function index(Request $request, BrowserSessionService $browserSessionService): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/sessions', [
            'sessions' => $browserSessionService->sessionsFor($user, $request->session()->getId()),
        ]);
    }

    public function destroy(Request $request, string $publicId, BrowserSessionService $browserSessionService, RevokeBrowserSessionAction $revokeBrowserSessionAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sessionId = $browserSessionService->sessionIdFor($user, $publicId);

        abort_if($sessionId === null || $sessionId === $request->session()->getId(), 404);

        $revokeBrowserSessionAction->execute($user, $sessionId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out of that browser.')]);

        return to_route('sessions.index');
    }

    public function destroyOthers(Request $request, RevokeOtherBrowserSessionsAction $revokeOtherBrowserSessionsAction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $revokeOtherBrowserSessionsAction->execute($user, $request->session()->getId());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Signed out of all other browsers.')]);

        return to_route('sessions.index');
    }
}
