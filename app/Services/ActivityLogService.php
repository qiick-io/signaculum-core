<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the filtered, newest-first activity log query of the activity log page.
 */
final class ActivityLogService
{
    /**
     * @param  array{search: string, user_ids: list<int>, event: string, log_name: string, date_from: string, date_to: string}  $filters
     * @return Builder<Activity>
     */
    public function filteredQuery(array $filters): Builder
    {
        $query = Activity::query()
            ->with(['causer', 'subject'])
            ->latest('id');

        if ($filters['user_ids'] !== []) {
            // Admin API actions are caused by the key's service account; filtering by a user also
            // shows those of the keys they created (`properties.api_key_id`, a UUID string, so
            // the JSON comparison behaves the same on every driver).
            $apiKeyIds = ApiKey::query()->whereIn('created_by', $filters['user_ids'])->pluck('uuid')->all();

            $query->where(function (Builder $causers) use ($filters, $apiKeyIds): void {
                // causer_id is a string column (UUID and integer keys share it); PostgreSQL
                // refuses to compare it with integer bindings.
                $causers->where(static fn (Builder $users) => $users
                    ->where('causer_type', (new User)->getMorphClass())
                    ->whereIn('causer_id', array_map(strval(...), $filters['user_ids'])));

                if ($apiKeyIds !== []) {
                    $causers->orWhere(static fn (Builder $servicePrincipal) => $servicePrincipal
                        ->where('causer_type', (new Principal)->getMorphClass())
                        ->whereIn('properties->api_key_id', $apiKeyIds));
                }
            });
        }

        if (filled($filters['event'])) {
            $query->forEvent($filters['event']);
        }

        if (filled($filters['log_name'])) {
            $query->inLog($filters['log_name']);
        }

        if (filled($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (filled($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (filled($filters['search'])) {
            $operator = $query->getModel()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $term = '%'.$filters['search'].'%';

            $query->where(function (Builder $inner) use ($operator, $term): void {
                $inner->where('description', $operator, $term)
                    ->orWhere('subject_type', $operator, $term)
                    ->orWhere('event', $operator, $term);
            });
        }

        return $query;
    }
}
