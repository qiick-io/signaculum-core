<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Database\Seeders\CreateSuperAdminSeeder;

/**
 * Initial admin password gate and seeder fail-closed behaviour (decision 2026-11-19 / #208).
 */
it('rejects the known default password outside local and testing', function (): void {
    $service = app(InitialSuperAdminPasswordService::class);

    expect($service->rejectionReasons('password', 'production'))->not->toBeEmpty()
        ->and($service->rejectionReasons('PASSWORD', 'staging'))->not->toBeEmpty()
        ->and($service->rejectionReasons('password', 'local'))->toBeEmpty()
        ->and($service->rejectionReasons('password', 'testing'))->toBeEmpty();
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
