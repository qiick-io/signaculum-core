<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\CreateUserAction;
use App\Actions\UpdateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListUsersRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\UserQueryService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin users list with create/edit drawer. Activation, 2FA reset and instance-admin
 * toggles are separate controllers.
 */
final class UserController extends Controller
{
    /**
     * Lists users with search, active/deactivated filter, sort and pagination.
     */
    public function index(ListUsersRequest $request, UserQueryService $userQueryService): Response
    {
        $filters = $request->filters();
        $users = $userQueryService->paginate($filters);

        return Inertia::render('admin/users/index', [
            'users' => UserResource::collection($users),
            'filters' => [
                'search' => $filters->search,
                'status' => $filters->status,
                'sort' => $filters->sort,
                'direction' => $filters->direction,
            ],
        ]);
    }

    /**
     * Creates a verified active user from the drawer.
     */
    public function store(StoreUserRequest $request, CreateUserAction $createUserAction): RedirectResponse
    {
        $createUserAction->execute(
            $request->string('first_name')->toString(),
            $request->filled('last_name') ? $request->string('last_name')->toString() : null,
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('admin.users.index');
    }

    /**
     * Updates name, email and optional password from the drawer.
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $updateUserAction): RedirectResponse
    {
        $updateUserAction->execute(
            $user,
            $request->string('first_name')->toString(),
            $request->filled('last_name') ? $request->string('last_name')->toString() : null,
            $request->string('email')->toString(),
            $request->filled('password') ? $request->string('password')->toString() : null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('admin.users.index');
    }
}
