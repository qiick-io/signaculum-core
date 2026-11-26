<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserGroupRequest;
use App\Http\Requests\Admin\UpdateUserGroupRequest;
use App\Http\Requests\Concerns\ValidatesListPerPageTrait;
use App\Http\Requests\Concerns\ValidatesSearchQueryTrait;
use App\Http\Resources\UserGroupResource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\SuperAdminGuardService;
use App\Services\InstanceAdminDemotionService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Qiick\Access\EffectivePermissionResolver;

/**
 * Admin list, create, edit and soft-delete for user groups and their memberships.
 *
 * An edit or deletion can take the admin role away from the group's members, so both run
 * through {@see InstanceAdminDemotionService} (`group_changed`).
 */
class UserGroupController extends Controller
{
    use ValidatesListPerPageTrait;
    use ValidatesSearchQueryTrait;

    public function index(Request $request): Response|JsonResponse
    {
        abort_unless(Gate::allows('viewAny', UserGroup::class), 403);

        $search = $this->validatedSearch($request);
        $sort = match ($request->string('sort')->toString()) {
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
            default => 'name',
        };
        $direction = strtolower($request->string('direction')->toString()) === 'desc' ? 'desc' : 'asc';
        $wantsJson = $request->expectsJson();

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

        if (filled($search)) {
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

        // sync() takes the ids as values, whatever the keys or their string form.
        $group->users()->sync($validated['user_ids'] ?? []);
        $group->roles()->sync($validated['role_ids'] ?? []);
        app(EffectivePermissionResolver::class)->forget();

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

        $this->groupMemberDemotions($group, function () use ($group, $validated, $nextUserIds, $nextRoleIds): void {
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
        });

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

        $this->groupMemberDemotions($group, function () use ($group): void {
            $group->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group deleted.')]);

        return to_route('admin.groups.index');
    }

    /**
     * @param  Closure(): void  $change
     */
    private function groupMemberDemotions(UserGroup $group, Closure $change): void
    {
        app(InstanceAdminDemotionService::class)->track(
            $group->users()->pluck('users.id'),
            InstanceAdminDemotionReasonEnum::GroupChanged,
            $change,
        );
    }
}
