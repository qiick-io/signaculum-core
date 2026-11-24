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
use App\Rules\FrontchannelLogoutUriRule;
use App\Rules\RedirectUriRule;
use App\Services\ApplicationQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'url'],
            'post_logout_redirect_uris' => ['sometimes', 'array'],
            'post_logout_redirect_uris.*' => ['required', 'string', new RedirectUriRule(isPostLogoutRedirectUri: true)],
            'is_confidential' => ['required', 'boolean'],
            'is_first_party' => ['required', 'boolean'],
            'is_client_credentials_enabled' => ['sometimes', 'boolean'],
            'is_device_authorization_enabled' => ['sometimes', 'boolean'],
            'is_pushed_authorization_required' => ['sometimes', 'boolean'],
            'frontchannel_logout_uri' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_frontchannel_logout_session_required' => ['sometimes', 'boolean'],
        ]);

        // One construction for both doors (#186): the admin form requests build the
        // payload here too, so a field added to ApplicationData cannot reach the web
        // form and silently miss the API.
        $applicationData = ApplicationData::fromValidated($validated);

        // Same normalisation and same rule as the admin form (StoreApplicationRequest),
        // so a URI is accepted or refused identically at both doors.
        if ($applicationData->frontchannelLogoutUri !== null) {
            $message = FrontchannelLogoutUriRule::failureMessageFor($applicationData->frontchannelLogoutUri);

            if ($message !== null) {
                throw ValidationException::withMessages(['frontchannel_logout_uri' => $message]);
            }
        }

        $application = $createApplicationAction->execute($applicationData);

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
