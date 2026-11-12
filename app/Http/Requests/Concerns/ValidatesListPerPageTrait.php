<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Http\Request;

/**
 * Shared "Rows per page" for admin list controllers (`per_page` query param).
 *
 * Unsupported values silently fall back to the default so hand-edited URLs keep working.
 */
trait ValidatesListPerPageTrait
{
    /** @var list<int> */
    public const LIST_PER_PAGE_OPTIONS = [10, 20, 50, 100];

    public const LIST_DEFAULT_PER_PAGE = 20;

    /**
     * Resolve `per_page` to one of {@see self::LIST_PER_PAGE_OPTIONS}.
     */
    protected function validatedListPerPage(
        Request $request,
        string $key = 'per_page',
        int $default = self::LIST_DEFAULT_PER_PAGE,
    ): int {
        $requested = $request->query($key);

        if (is_string($requested) && ctype_digit($requested) && in_array((int) $requested, self::LIST_PER_PAGE_OPTIONS, true)) {
            return (int) $requested;
        }

        return $default;
    }
}
