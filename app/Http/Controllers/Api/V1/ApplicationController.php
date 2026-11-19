<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateApplicationAction;
use App\Actions\RegenerateApplicationSecretAction;
use App\Data\ApplicationData;
use App\Data\ApplicationFilterData;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ApplicationResource;
use App\Models\Client;
use App\Services\ApplicationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Admin REST API: applications list/show/create/rotate secret.
 */
final class ApplicationController extends Controller
{
    public function index(Request $request, ApplicationQueryService $applicationQueryService): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Client::class);

        $perPage = min(
            max(1, (int) $request->integer('per_page', (int) config('api.default_per_page', 25))),
            (int) config('api.max_per_page', 100),
        );

        $filters = new ApplicationFilterData(
            search: trim((string) $request->query('search', '')),
            sort: in_array($request->query('sort'), ApplicationQueryService::SORTABLE_COLUMNS, true)
                ? (string) $request->query('sort')
                : 'created_at',
            direction: $request->query('direction') === 'asc' ? 'asc' : 'desc',
            perPage: $perPage,
        );

        return ApplicationResource::collection($applicationQueryService->paginate($filters));
    }

    public function show(Client $application): ApplicationResource
    {
        Gate::authorize('update', $application);

        return new ApplicationResource($application);
    }

    public function store(Request $request, CreateApplicationAction $createApplicationAction): JsonResponse
    {
        Gate::authorize('create', Client::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'url'],
            'is_confidential' => ['required', 'boolean'],
            'is_first_party' => ['required', 'boolean'],
            'is_client_credentials_enabled' => ['sometimes', 'boolean'],
            'is_pushed_authorization_required' => ['sometimes', 'boolean'],
        ]);

        /** @var list<string> $redirectUris */
        $redirectUris = array_values($validated['redirect_uris']);

        $application = $createApplicationAction->execute(new ApplicationData(
            name: $validated['name'],
            redirectUris: $redirectUris,
            isConfidential: (bool) $validated['is_confidential'],
            isFirstParty: (bool) $validated['is_first_party'],
            isClientCredentialsEnabled: (bool) ($validated['is_client_credentials_enabled'] ?? false),
            isPushedAuthorizationRequired: (bool) ($validated['is_pushed_authorization_required'] ?? false),
        ));

        $resource = (new ApplicationResource($application))->resolve();

        if (is_string($application->plainSecret)) {
            $resource['plain_text_secret'] = $application->plainSecret;
        }

        return response()->json(['data' => $resource], 201);
    }

    public function rotateSecret(
        Client $application,
        RegenerateApplicationSecretAction $regenerateApplicationSecretAction,
    ): JsonResponse {
        Gate::authorize('update', $application);

        $application = $regenerateApplicationSecretAction->execute($application);
        $resource = (new ApplicationResource($application))->resolve();

        if (is_string($application->plainSecret)) {
            $resource['plain_text_secret'] = $application->plainSecret;
        }

        return response()->json(['data' => $resource]);
    }
}
