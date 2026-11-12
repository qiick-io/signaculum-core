<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\UserFilterData;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Builds the admin users list query: search, active/deactivated filter, sort and pagination.
 */
final class UserQueryService
{
    /**
     * @var list<string>
     */
    public const SORTABLE_COLUMNS = ['name', 'email', 'is_active', 'created_at', 'updated_at'];

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(UserFilterData $filters): LengthAwarePaginator
    {
        $query = User::query();

        if ($filters->search !== '') {
            $term = '%'.$filters->search.'%';
            $like = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where(function ($inner) use ($term, $like): void {
                $inner->where('first_name', $like, $term)
                    ->orWhere('last_name', $like, $term)
                    ->orWhere('email', $like, $term);
            });
        }

        if ($filters->status === 'active') {
            $query->where('is_active', true);
        }

        if ($filters->status === 'deactivated') {
            $query->where('is_active', false);
        }

        if ($filters->sort === 'name') {
            $query->orderBy('first_name', $filters->direction)
                ->orderBy('last_name', $filters->direction);
        } else {
            $query->orderBy($filters->sort, $filters->direction);
        }

        return $query
            ->orderByDesc('id')
            ->paginate($filters->perPage)
            ->withQueryString();
    }
}
