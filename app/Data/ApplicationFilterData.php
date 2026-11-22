<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Admin applications list filters: search, sort and page size.
 */
final readonly class ApplicationFilterData
{
    public function __construct(
        public string $search,
        public string $sort,
        public string $direction,
        public int $perPage,
    ) {}
}
