<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\RegenerateApplicationSecretAction;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Regenerates the client secret for a confidential OIDC application (shown once).
 */
final class ApplicationSecretController extends Controller
{
    /**
     * Issues a new secret and flashes the plain value for the next index render.
     */
    public function update(
        Request $request,
        Client $application,
        RegenerateApplicationSecretAction $regenerateApplicationSecretAction,
    ): RedirectResponse {
        Gate::authorize('update', $application);

        if (! $application->confidential()) {
            throw new AccessDeniedHttpException('Public clients have no client secret.');
        }

        /** @var User $actor */
        $actor = $request->user();
        $application = $regenerateApplicationSecretAction->execute($application, $actor);

        if (is_string($application->plainSecret)) {
            $request->session()->flash('plain_text_client_secret', $application->plainSecret);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client secret regenerated.')]);

        return to_route('admin.applications.index');
    }
}
