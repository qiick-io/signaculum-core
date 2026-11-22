<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserGroupRequest;
use App\Http\Requests\Admin\UpdateUserGroupRequest;
use App\Http\Requests\Concerns\ValidatesListPerPageTrait;
use App\Http\Requests\Concerns\ValidatesSearchQueryTrait;
use App\Http\Resources\UserGroupResource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin list, create, edit and soft-delete for user groups and their memberships.
 */
class UserGroupController extends Controller
{
    use ValidatesListPerPageTrait;
    use ValidatesSearchQueryTrait;

    /**
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['name', 'created_at', 'updated_at'];

    public function index(Request $request): Response|JsonResponse
    {
        abort_unless(Gate::allows('viewAny', UserGroup::class), 403);

        $search = $this->validatedSearch($request);
        $sort = $request->string('sort')->toString();
        $direction = strtolower($request->string('direction')->toString()) === 'desc' ? 'desc' : 'asc';
        $wantsJson = $request->expectsJson();

        if (! in_array($sort, self::SORTABLE_COLUMNS, true)) {
            $sort = 'name';
        }

        $query = UserGroup::query()->withCount(['users', 'roles']);

        if (! $wantsJson) {
            $query->with([
                'roles:id,name',
                'users:id,first_name,last_name,email',
            ]);
        }

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $like = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($builder) use ($search, $like): void {
                $builder->where('name', $like, "%{$search}%")
                    ->orWhere('description', $like, "%{$search}%");
            });
        }

        $paginator = $query
            ->orderBy($sort, $direction)
            ->paginate($this->validatedListPerPage($request))
            ->withQueryString();

        if ($wantsJson) {
            return UserGroupResource::collection($paginator)->response($request);
        }

        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (UserGroup $group): array => (new UserGroupResource($group))->toArray($request)
            )
        );

        return Inertia::render('admin/groups/index', [
            'groups' => $paginator,
            'filters' => [
                'search' => $search,
                'trashed' => $request->boolean('trashed'),
                'sort' => $sort,
                'direction' => $direction,
            ],
            'roles' => Role::query()->where('is_assignable', true)->orderBy('name')->get(['id', 'name']),
            'users' => User::query()
                ->where('is_active', true)
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->map(static fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(StoreUserGroupRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $group = UserGroup::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $group->users()->sync(array_values(array_map('intval', $validated['user_ids'] ?? [])));
        $group->roles()->sync(array_values(array_map('intval', $validated['role_ids'] ?? [])));
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created.')]);

        return to_route('admin.groups.index');
    }

    public function update(UpdateUserGroupRequest $request, UserGroup $group): RedirectResponse
    {
        $validated = $request->validated();
        $guard = app(SuperAdminGuardService::class);

        $nextUserIds = array_key_exists('user_ids', $validated)
            ? array_values(array_map('intval', $validated['user_ids'] ?? []))
            : null;
        $nextRoleIds = array_key_exists('role_ids', $validated)
            ? array_values(array_map('intval', $validated['role_ids'] ?? []))
            : null;

        if ($guard->wouldLeaveNoActiveSuperAdminAfterGroupUpdate($group, $nextUserIds, $nextRoleIds)) {
            $errorKey = $nextRoleIds !== null && ! $guard->hasSuperAdminRole($nextRoleIds)
                ? 'role_ids'
                : 'user_ids';

            throw ValidationException::withMessages([
                $errorKey => __('Cannot remove the last super-admin path from a group.'),
            ]);
        }

        $group->getConnection()->transaction(function () use ($group, $validated, $nextUserIds, $nextRoleIds): void {
            $group->fill([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
            ])->save();

            if ($nextUserIds !== null) {
                $group->users()->sync($nextUserIds);
            }

            if ($nextRoleIds !== null) {
                $group->roles()->sync($nextRoleIds);
            }
        });

        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group updated.')]);

        return to_route('admin.groups.index');
    }

    public function destroy(UserGroup $group): RedirectResponse
    {
        Gate::authorize('delete', $group);

        if (app(SuperAdminGuardService::class)->wouldRemoveLastActiveSuperAdminViaGroups([$group->id])) {
            throw ValidationException::withMessages([
                'group' => __('Cannot delete the group that is the last super-admin path.'),
            ]);
        }

        $group->delete();
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group deleted.')]);

        return to_route('admin.groups.index');
    }
}
