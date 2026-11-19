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
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        ]);
    }

    public function store(StoreUserGroupRequest $request): RedirectResponse
    {
        $group = UserGroup::query()->create([
            'name' => $request->string('name')->toString(),
            'description' => $request->filled('description') ? $request->string('description')->toString() : null,
        ]);

        $group->users()->sync($request->input('user_ids', []));
        $group->roles()->sync($request->input('role_ids', []));
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group created.')]);

        return to_route('admin.groups.index');
    }

    public function update(UpdateUserGroupRequest $request, UserGroup $group): RedirectResponse
    {
        $group->fill([
            'name' => $request->string('name')->toString(),
            'description' => $request->filled('description') ? $request->string('description')->toString() : null,
        ])->save();

        $group->users()->sync($request->input('user_ids', []));
        $group->roles()->sync($request->input('role_ids', []));
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group updated.')]);

        return to_route('admin.groups.index');
    }

    public function destroy(UserGroup $group): RedirectResponse
    {
        Gate::authorize('delete', $group);
        $group->delete();
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group deleted.')]);

        return to_route('admin.groups.index');
    }
}
