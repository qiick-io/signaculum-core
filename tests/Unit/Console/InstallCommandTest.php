<?php

declare(strict_types=1);

use App\Console\Commands\InstallCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

test('signaculum:install is registered', function () {
    expect(Artisan::all())->toHaveKey('signaculum:install');
});

test('install refuses a production .env without --force', function () {
    $path = base_path('.env');
    $backup = File::exists($path) ? File::get($path) : null;
    File::put($path, "APP_ENV=production\nAPP_KEY=\n");

    try {
        $this->artisan(InstallCommand::class)
            ->expectsOutputToContain('Refusing: APP_ENV looks like production')
            ->assertFailed();
    } finally {
        restoreInstallEnv($path, $backup);
    }
});

test('install migrates sqlite, creates keys and the first administrator', function () {
    [$envPath, $envBackup] = beginInstallFixtures();
    File::delete(Passport::keyPath('oauth-private.key'));
    File::delete(Passport::keyPath('oauth-public.key'));
    putenv('SIGNACULUM_ADMIN_EMAIL=admin@example.com');
    putenv('SIGNACULUM_ADMIN_PASSWORD=password');
    $_ENV['SIGNACULUM_ADMIN_EMAIL'] = 'admin@example.com';
    $_ENV['SIGNACULUM_ADMIN_PASSWORD'] = 'password';

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Install complete.')
            ->assertSuccessful();

        config([
            'database.connections.sqlite.database' => database_path('database.sqlite'),
        ]);
        DB::purge('sqlite');

        $password = DB::connection('sqlite')->table('users')->where('email', 'admin@example.com')->value('password');

        expect(Schema::connection('sqlite')->hasTable('users'))->toBeTrue()
            ->and(Schema::connection('sqlite')->hasTable('signing_keys'))->toBeTrue()
            ->and(DB::connection('sqlite')->table('users')->count())->toBe(1)
            ->and(DB::connection('sqlite')->table('signing_keys')->whereNull('retired_at')->count())->toBe(1)
            ->and(is_string($password) && Hash::check('password', $password))->toBeTrue()
            ->and(File::exists(Passport::keyPath('oauth-private.key')))->toBeTrue()
            ->and(File::get($envPath))->toContain('APP_NAME=Signaculum')
            ->and(File::get($envPath))->toContain('DB_CONNECTION=sqlite');
    } finally {
        putenv('SIGNACULUM_ADMIN_EMAIL');
        putenv('SIGNACULUM_ADMIN_PASSWORD');
        unset($_ENV['SIGNACULUM_ADMIN_EMAIL'], $_ENV['SIGNACULUM_ADMIN_PASSWORD']);
        restoreInstallEnv($envPath, $envBackup);
    }
});

/**
 * @return array{0: string, 1: string|null}
 */
function beginInstallFixtures(): array
{
    $envPath = base_path('.env');
    $backup = File::exists($envPath) ? File::get($envPath) : null;
    File::put(database_path('database.sqlite'), '');

    return [$envPath, $backup];
}

function restoreInstallEnv(string $envPath, ?string $backup): void
{
    if ($backup === null) {
        File::delete($envPath);

        return;
    }

    File::put($envPath, $backup);
}
