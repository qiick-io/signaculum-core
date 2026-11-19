<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityEventEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivityLogRequest;
use App\Http\Resources\ActivityLogResource;
use App\Models\Activity;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activity log browser with user, action, log and date filters, for instance admins
 * (ActivityPolicy). Copied from qiick-io/excubia-app and adapted to Signaculum admin.
 */
final class ActivityLogController extends Controller
{
    public function index(ActivityLogRequest $request, ActivityLogService $activityLogs): Response
    {
        Gate::authorize('viewAny', Activity::class);

        $filters = $request->activityFilters();

        return Inertia::render('admin/activity-logs/index', [
            'activityLogs' => ActivityLogResource::collection($activityLogs->filteredQuery($filters)
                ->paginate($request->perPage())
                ->withQueryString()),
            // Options for the user filter (NativeSelect). Cap keeps the page light.
            'users' => User::query()
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(100)
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]),
            'events' => ActivityEventEnum::values(),
            'logNames' => ActivityLogRequest::LOG_NAMES,
            'filters' => $filters,
        ]);
    }
}
