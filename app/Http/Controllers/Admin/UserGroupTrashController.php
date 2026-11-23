<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkUserGroupActionRequest;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Restore and permanent delete for soft-deleted user groups.
 */
class UserGroupTrashController extends Controller
{
    public function restore(int $group): RedirectResponse
    {
        Gate::authorize('restoreAny', UserGroup::class);
        $userGroup = UserGroup::onlyTrashed()->findOrFail($group);
        Gate::authorize('restore', $userGroup);
        $userGroup->restore();
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group restored.')]);

        return to_route('admin.groups.index', ['trashed' => 1]);
    }

    public function forceDestroy(int $group): RedirectResponse
    {
        Gate::authorize('forceDeleteAny', UserGroup::class);
        $userGroup = UserGroup::onlyTrashed()->findOrFail($group);
        Gate::authorize('forceDelete', $userGroup);

        if (app(SuperAdminGuardService::class)->wouldRemoveLastActiveSuperAdminViaGroups([$userGroup->id])) {
            throw ValidationException::withMessages([
                'group' => __('Cannot permanently delete the group that is the last super-admin path.'),
            ]);
        }

        $userGroup->forceDelete();
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group permanently deleted.')]);

        return to_route('admin.groups.index', ['trashed' => 1]);
    }

    public function bulk(BulkUserGroupActionRequest $request): RedirectResponse
    {
        $ids = $request->input('ids', []);
        $action = $request->string('action')->toString();
        $groups = UserGroup::onlyTrashed()->whereIn('id', $ids)->get();

        if ($action !== 'restore' && app(SuperAdminGuardService::class)->wouldRemoveLastActiveSuperAdminViaGroups($ids)) {
            throw ValidationException::withMessages([
                'ids' => __('Cannot permanently delete the group that is the last super-admin path.'),
            ]);
        }

        $ability = $action === 'restore' ? 'restore' : 'forceDelete';

        // Every group is authorized before any is touched: a permanent deletion cannot be
        // undone, so a batch the actor may only partly handle changes nothing.
        foreach ($groups as $group) {
            Gate::authorize($ability, $group);
        }

        UserGroup::query()->getConnection()->transaction(function () use ($groups, $action): void {
            foreach ($groups as $group) {
                if ($action === 'restore') {
                    $group->restore();
                } else {
                    $group->forceDelete();
                }
            }
        });

        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $action === 'restore' ? __('Groups restored.') : __('Groups permanently deleted.'),
        ]);

        return to_route('admin.groups.index', ['trashed' => 1]);
    }
}
