<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateApplicationAction;
use App\Actions\DeleteApplicationAction;
use App\Actions\UpdateApplicationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeleteApplicationRequest;
use App\Http\Requests\Admin\ListApplicationsRequest;
use App\Http\Requests\Admin\StoreApplicationRequest;
use App\Http\Requests\Admin\UpdateApplicationRequest;
use App\Http\Resources\ApplicationResource;
use App\Models\Client;
use App\Services\ApplicationQueryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin applications list with create/edit drawer for OIDC clients.
 * Secret regenerate lives on {@see ApplicationSecretController}.
 */
final class ApplicationController extends Controller
{
    /**
     * Lists non-revoked applications with search, sort and pagination.
     */
    public function index(ListApplicationsRequest $request, ApplicationQueryService $applicationQueryService): Response
    {
        $filters = $request->filters();
        $applications = $applicationQueryService->paginate($filters);

        return Inertia::render('admin/applications/index', [
            'applications' => ApplicationResource::collection($applications),
            'filters' => [
                'search' => $filters->search,
                'sort' => $filters->sort,
                'direction' => $filters->direction,
            ],
            'plainTextSecret' => $request->session()->pull('plain_text_client_secret'),
        ]);
    }

    /**
     * Registers a new authorization-code application from the drawer.
     */
    public function store(StoreApplicationRequest $request, CreateApplicationAction $createApplicationAction): RedirectResponse
    {
        $application = $createApplicationAction->execute($request->toData());

        if (is_string($application->plainSecret)) {
            $request->session()->flash('plain_text_client_secret', $application->plainSecret);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application created.')]);

        return to_route('admin.applications.index');
    }

    /**
     * Updates name, redirect URIs and flags from the drawer.
     */
    public function update(
        UpdateApplicationRequest $request,
        Client $application,
        UpdateApplicationAction $updateApplicationAction,
    ): RedirectResponse {
        $updateApplicationAction->execute($application, $request->toData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application updated.')]);

        return to_route('admin.applications.index');
    }

    /**
     * Revokes the application after typed name confirmation.
     */
    public function destroy(
        DeleteApplicationRequest $request,
        Client $application,
        DeleteApplicationAction $deleteApplicationAction,
    ): RedirectResponse {
        $deleteApplicationAction->execute($application);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Application deleted.')]);

        return to_route('admin.applications.index');
    }
}
