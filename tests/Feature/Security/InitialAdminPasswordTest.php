<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Database\Seeders\CreateSuperAdminSeeder;
use Spatie\Permission\Models\Permission;

/**
 * Initial admin password gate and seeder fail-closed behaviour (decision 2026-11-19 / #208).
 */
it('rejects the known default password outside local and testing', function (): void {
    $service = app(InitialSuperAdminPasswordService::class);

    expect($service->rejectionReasons(null, 'production'))->not->toBeEmpty()
        ->and($service->rejectionReasons('', 'production'))->not->toBeEmpty()
        ->and($service->rejectionReasons('password', 'production'))->not->toBeEmpty()
        ->and($service->rejectionReasons('PASSWORD', 'staging'))->not->toBeEmpty()
        ->and($service->rejectionReasons('password', 'local'))->toBeEmpty()
        ->and($service->rejectionReasons('password', 'testing'))->toBeEmpty()
        ->and($service->rejectionReasons('correct-horse-battery-staple', 'production'))->toBeEmpty();
});

it('refuses create-user with the known default password outside local and testing', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('signaculum:create-user', [
        '--first-name' => 'Prod',
        '--last-name' => 'Admin',
        '--email' => 'prod-admin-'.uniqid('', true).'@example.com',
        '--password' => 'password',
        '--role' => 'super-admin',
        '--no-interaction' => true,
    ])->assertFailed();

    expect(User::query()->where('email', 'like', 'prod-admin-%')->exists())->toBeFalse();
});

it('creates a user through create-user and refuses invalid email payloads', function (): void {
    syncPermissions();

    $email = 'cli-user-'.uniqid('', true).'@example.com';

    $this->artisan('signaculum:create-user', [
        '--first-name' => 'Cli',
        '--last-name' => 'User',
        '--email' => $email,
        '--password' => 'correct-horse-battery',
        '--role' => 'reader',
        '--no-interaction' => true,
    ])->assertSuccessful();

    expect(User::query()->where('email', $email)->exists())->toBeTrue();

    $this->artisan('signaculum:create-user', [
        '--first-name' => 'Bad',
        '--email' => 'not-an-email',
        '--password' => 'correct-horse-battery',
        '--role' => 'reader',
        '--no-interaction' => true,
    ])->assertFailed();
});

it('prunes stale permissions when sync-permissions runs with --prune', function (): void {
    syncPermissions();

    Permission::query()->create([
        'name' => 'can-stale-permission',
        'guard_name' => 'web',
    ]);

    $this->artisan('signaculum:sync-permissions', ['--prune' => true])->assertSuccessful();

    expect(Permission::query()->where('name', 'can-stale-permission')->exists())->toBeFalse();
});

it('does not reactivate or promote an existing user when the seeder runs again', function (): void {
    syncPermissions();

    config([
        'super_admin.email' => 'already@example.com',
        'super_admin.password' => 'password',
        'super_admin.first_name' => 'Should',
        'super_admin.last_name' => 'NotChange',
    ]);

    $existing = User::factory()->create([
        'email' => 'already@example.com',
        'first_name' => 'Existing',
        'last_name' => 'User',
        'is_active' => false,
    ]);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class])
        ->expectsOutputToContain('already exists')
        ->assertSuccessful();

    $existing->refresh();

    expect($existing->first_name)->toBe('Existing')
        ->and($existing->is_active)->toBeFalse()
        ->and($existing->hasRole('super-admin'))->toBeFalse();
});
