<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsRepositoryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: settings move to (scope, scope_id, group, key) while
 * SettingsRepositoryService public signatures stay the same (#192).
 *
 * Grouping: SettingsRepositoryService stores flat keys (`app_name`,
 * `password_min_length`, …) with no prefix convention, so every migrated row
 * lands in group=`app` under scope=`instance`.
 */
test('settings migration moves flat rows to instance scope with identical values', function (): void {
    Schema::drop('settings');

    Schema::create('settings', function (Blueprint $table): void {
        $table->id();
        $table->string('key')->unique();
        $table->json('value')->nullable();
        $table->timestamps();
    });

    $now = now();

    DB::table('settings')->insert([
        [
            'key' => 'app_name',
            'value' => json_encode('Migrated Signaculum', JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'key' => 'registration_enabled',
            'value' => json_encode(true, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $migration = require database_path('migrations/2026_11_19_210200_scope_settings_table.php');
    $migration->up();

    expect(Schema::hasColumns('settings', ['scope', 'scope_id', 'group', 'key', 'value']))->toBeTrue();

    $appName = DB::table('settings')
        ->where('scope', 'instance')
        ->whereNull('scope_id')
        ->where('group', 'app')
        ->where('key', 'app_name')
        ->first();

    expect($appName)->not->toBeNull();

    $decodedName = is_string($appName->value)
        ? json_decode($appName->value, true, 512, JSON_THROW_ON_ERROR)
        : $appName->value;

    expect($decodedName)->toBe('Migrated Signaculum');

    $registration = DB::table('settings')
        ->where('scope', 'instance')
        ->where('group', 'app')
        ->where('key', 'registration_enabled')
        ->first();

    $decodedRegistration = is_string($registration->value)
        ? json_decode($registration->value, true, 512, JSON_THROW_ON_ERROR)
        : $registration->value;

    expect($decodedRegistration)->toBeTrue();
});

test('settings repository still reads and writes through the public API', function (): void {
    $settings = app(SettingsRepositoryService::class);

    $settings->save([
        'app_name' => 'Scoped Instance',
        'login_max_attempts' => 7,
    ]);

    $fresh = app(SettingsRepositoryService::class);

    expect($fresh->appName())->toBe('Scoped Instance')
        ->and($fresh->all()['login_max_attempts'])->toBe(7);

    $row = Setting::query()
        ->where('scope', 'instance')
        ->whereNull('scope_id')
        ->where('group', 'app')
        ->where('key', 'app_name')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->value)->toBe('Scoped Instance');
});
