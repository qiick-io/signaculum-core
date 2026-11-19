<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Route keys that are uuid columns must not be bound with a non-UUID string: PostgreSQL
 * raises SQLSTATE 22P02 (500) instead of a clean 404 (#197).
 */
trait RejectsInvalidUuidRouteKeysTrait
{
    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
