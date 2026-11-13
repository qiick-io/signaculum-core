<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Short database probe for the readiness endpoint.
 *
 * Redis is not checked: the default install uses the database for cache, queue
 * and sessions. A failing probe returns `fail` and never the exception text,
 * which can contain a connection string.
 */
final class HealthReadinessService
{
    /**
     * @return array<string, string> check name => `ok` or `fail`
     */
    public function run(): array
    {
        return [
            'database' => $this->database(),
        ];
    }

    public function database(): string
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return 'ok';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
