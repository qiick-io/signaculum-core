<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Services\HealthReadinessService;
use Illuminate\Support\Facades\DB;

test('readiness is 503 when the database file cannot be opened', function () {
    $originalDatabase = config('database.connections.sqlite.database');
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => '/tmp/signaculum-missing-directory/database.sqlite',
    ]);
    DB::purge('sqlite');

    try {
        expect(app(HealthReadinessService::class)->database())->toBe('fail');

        $response = app(HealthController::class)->ready();

        expect($response->getStatusCode())->toBe(503)
            ->and($response->getData(true))->toMatchArray([
                'status' => 'fail',
                'checks' => ['database' => 'fail'],
            ]);
    } finally {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $originalDatabase,
        ]);
        DB::purge('sqlite');
    }
});
