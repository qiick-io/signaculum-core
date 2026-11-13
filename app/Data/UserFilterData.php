<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated list filters for the admin users index (search, status, sort, page size).
 */
final readonly class UserFilterData
{
    /**
     * @param  'active'|'deactivated'|null  $status  null = every user
     * @param  'asc'|'desc'  $direction
     */
    public function __construct(
        public string $search,
        public ?string $status,
        public string $sort,
        public string $direction,
        public int $perPage,
    ) {}
}
