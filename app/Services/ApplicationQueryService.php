<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\ApplicationFilterData;
use App\Models\Client;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Builds the admin applications list query: search, sort and pagination (non-revoked only).
 */
final class ApplicationQueryService
{
    /**
     * @var list<string>
     */
    public const SORTABLE_COLUMNS = ['name', 'created_at', 'updated_at'];

    /**
     * @return LengthAwarePaginator<int, Client>
     */
    public function paginate(ApplicationFilterData $filters): LengthAwarePaginator
    {
        $query = Client::query()->where('revoked', false);

        if ($filters->search !== '') {
            $term = '%'.$filters->search.'%';
            $like = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $query->where('name', $like, $term);
        }

        $direction = $filters->direction === 'asc' ? 'asc' : 'desc';
        $query->orderBy($filters->sort, $direction);

        return $query
            ->orderByDesc('id')
            ->paginate($filters->perPage)
            ->withQueryString();
    }
}
