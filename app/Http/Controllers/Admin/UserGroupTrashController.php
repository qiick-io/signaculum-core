<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkUserGroupActionRequest;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Restore and permanent delete for soft-deleted user groups.
 */
class UserGroupTrashController extends Controller
{
    public function restore(int $group): RedirectResponse
    {
        $userGroup = UserGroup::onlyTrashed()->findOrFail($group);
        Gate::authorize('restore', $userGroup);
        $userGroup->restore();
        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Group restored.')]);

        return to_route('admin.groups.index', ['trashed' => 1]);
    }

    public function forceDestroy(int $group): RedirectResponse
    {
        $userGroup = UserGroup::onlyTrashed()->findOrFail($group);
        Gate::authorize('forceDelete', $userGroup);
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

        foreach ($groups as $group) {
            if ($action === 'restore') {
                Gate::authorize('restore', $group);
                $group->restore();
            } else {
                Gate::authorize('forceDelete', $group);
                $group->forceDelete();
            }
        }

        app(EffectivePermissionResolverService::class)->forget();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $action === 'restore' ? __('Groups restored.') : __('Groups permanently deleted.'),
        ]);

        return to_route('admin.groups.index', ['trashed' => 1]);
    }
}
