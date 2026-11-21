<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\CreateSuperAdminSeeder;

/**
 * The development super-admin: `db:seed` never creates a known-credential account on a
 * server, and never one without a password set in `.env`.
 */
beforeEach(function (): void {
    config([
        'super_admin.email' => 'superadmin@example.com',
        'super_admin.password' => 'password',
        'super_admin.first_name' => 'Super',
        'super_admin.last_name' => 'Admin',
    ]);
});

it('creates no user when the seeder is run explicitly in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class, '--force' => true])
        ->expectsOutputToContain('Skipped the initial super-admin')
        ->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
});

it('creates no user locally while INITIAL_SUPER_ADMIN_PASSWORD is empty', function (?string $password): void {
    config(['super_admin.password' => $password]);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class])->assertSuccessful();

    expect(User::query()->exists())->toBeFalse();
})->with(['empty' => [''], 'unset' => [null]]);

it('creates the super-admin in local when a password is set', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config(['super_admin.password' => 'password']);

    $this->artisan('db:seed', ['--class' => CreateSuperAdminSeeder::class])->assertSuccessful();

    $user = User::query()->where('email', 'superadmin@example.com')->sole();

    expect($user->isSuperAdmin())->toBeTrue();
});
