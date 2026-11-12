<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Http\Request;

/**
 * Shared search query string for admin list controllers (`search` query param).
 */
trait ValidatesSearchQueryTrait
{
    public const SEARCH_MAX_LENGTH = 255;

    /**
     * Trimmed search string, empty when missing or over the length ceiling.
     */
    protected function validatedSearch(Request $request, string $key = 'search'): string
    {
        $search = trim($request->string($key)->toString());

        if ($search === '' || mb_strlen($search) > self::SEARCH_MAX_LENGTH) {
            return '';
        }

        return $search;
    }
}
