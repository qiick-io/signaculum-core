<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateUserAction;
use App\Actions\UpdateUserAction;
use App\Data\UserFilterData;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\UserQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Password;

/**
 * Admin REST API: users list/show/create/update/deactivate/reset 2FA.
 */
final class UserController extends Controller
{
    public function index(Request $request, UserQueryService $userQueryService): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $perPage = min(
            max(1, (int) $request->integer('per_page', (int) config('api.default_per_page', 25))),
            (int) config('api.max_per_page', 100),
        );

        $filters = new UserFilterData(
            search: trim((string) $request->query('search', '')),
            status: in_array($request->query('status'), ['active', 'deactivated'], true)
                ? (string) $request->query('status')
                : 'active',
            sort: in_array($request->query('sort'), UserQueryService::SORTABLE_COLUMNS, true)
                ? (string) $request->query('sort')
                : 'created_at',
            direction: $request->query('direction') === 'asc' ? 'asc' : 'desc',
            perPage: $perPage,
        );

        return UserResource::collection($userQueryService->paginate($filters));
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('update', $user);

        return new UserResource($user);
    }

    public function store(Request $request, CreateUserAction $createUserAction): JsonResponse
    {
        Gate::authorize('create', User::class);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ]);

        $user = $createUserAction->execute(
            $validated['first_name'],
            $validated['last_name'] ?? null,
            $validated['email'],
            $validated['password'],
        );

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, User $user, UpdateUserAction $updateUserAction): UserResource
    {
        Gate::authorize('update', $user);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->getKey()],
            'password' => ['nullable', 'string', Password::defaults()],
        ]);

        $user = $updateUserAction->execute(
            $user,
            $validated['first_name'],
            $validated['last_name'] ?? null,
            $validated['email'],
            $validated['password'] ?? null,
        );

        return new UserResource($user);
    }
}
