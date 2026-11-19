<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateOrganizationAction;
use App\Data\OrganizationData;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrganizationResource;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Admin REST API: organisations list/show/create.
 */
final class OrganizationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Organization::class);

        $perPage = min(
            max(1, (int) $request->integer('per_page', (int) config('api.default_per_page', 25))),
            (int) config('api.max_per_page', 100),
        );

        $query = Organization::query()->orderBy('name');

        $search = trim((string) $request->query('search', ''));

        if ($search !== '') {
            $like = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $term = '%'.$search.'%';
            $query->where(function ($builder) use ($like, $term): void {
                $builder->where('name', $like, $term)->orWhere('slug', $like, $term);
            });
        }

        return OrganizationResource::collection($query->paginate($perPage)->withQueryString());
    }

    public function show(Organization $organization): OrganizationResource
    {
        Gate::authorize('view', $organization);

        return new OrganizationResource($organization);
    }

    public function store(Request $request, CreateOrganizationAction $createOrganizationAction): JsonResponse
    {
        Gate::authorize('create', Organization::class);

        /** @var User $creator */
        $creator = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:organizations,slug'],
        ]);

        // One construction for both doors (#186): the web form requests build the
        // payload here too, so a field added to OrganizationData cannot reach the
        // settings page and silently miss the API.
        $organization = $createOrganizationAction->execute(
            OrganizationData::fromValidated($validated),
            $creator,
        );

        return (new OrganizationResource($organization))
            ->response()
            ->setStatusCode(201);
    }
}
