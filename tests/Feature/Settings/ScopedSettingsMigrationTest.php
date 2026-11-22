<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: settings are scoped (scope, scope_id, group, key).
 * SettingsRepositoryService public signatures stay flat-keyed (#192 / #211).
 */
test('settings table ships with the scoped shape', function (): void {
    expect(Schema::hasColumns('settings', ['scope', 'scope_id', 'group', 'key', 'value']))->toBeTrue();
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
        ->where('scope_id', '')
        ->where('group', 'app')
        ->where('key', 'app_name')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row?->value)->toBe('Scoped Instance');
});
