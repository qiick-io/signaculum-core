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

test('install refuses when .env.example is missing', function () {
    $examplePath = base_path('.env.example');
    $exampleBackup = File::exists($examplePath) ? File::get($examplePath) : null;
    File::delete($examplePath);

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('.env.example is missing')
            ->assertFailed();
    } finally {
        if ($exampleBackup !== null) {
            File::put($examplePath, $exampleBackup);
        }
    }
});

test('install refuses an existing .env without --force in non-interactive mode', function () {
    $path = base_path('.env');
    $backup = File::exists($path) ? File::get($path) : null;
    File::put($path, "APP_ENV=local\nAPP_KEY=base64:dGVzdA==\n");

    try {
        $this->artisan(InstallCommand::class, [
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('.env already exists')
            ->assertFailed();
    } finally {
        restoreInstallEnv($path, $backup);
    }
});

test('install skips creating an administrator when a user already exists', function () {
    [$envPath, $envBackup] = beginInstallFixtures();
    File::delete(Passport::keyPath('oauth-private.key'));
    File::delete(Passport::keyPath('oauth-public.key'));

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        config([
            'database.connections.sqlite.database' => database_path('database.sqlite'),
        ]);
        DB::purge('sqlite');

        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('An account already exists')
            ->assertSuccessful();

        expect(DB::connection('sqlite')->table('users')->count())->toBe(1);
    } finally {
        restoreInstallEnv($envPath, $envBackup);
    }
});

test('install migrates sqlite, creates keys and the first administrator', function () {
    [$envPath, $envBackup] = beginInstallFixtures();
    File::delete(Passport::keyPath('oauth-private.key'));
    File::delete(Passport::keyPath('oauth-public.key'));
    putenv('SIGNACULUM_ADMIN_EMAIL=admin@example.com');
    putenv('SIGNACULUM_ADMIN_PASSWORD=password');
    putenv('SIGNACULUM_ADMIN_FIRST_NAME=Ada');
    putenv('SIGNACULUM_ADMIN_LAST_NAME=Lovelace');
    $_ENV['SIGNACULUM_ADMIN_EMAIL'] = 'admin@example.com';
    $_ENV['SIGNACULUM_ADMIN_PASSWORD'] = 'password';
    $_ENV['SIGNACULUM_ADMIN_FIRST_NAME'] = 'Ada';
    $_ENV['SIGNACULUM_ADMIN_LAST_NAME'] = 'Lovelace';

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

        $administrator = DB::connection('sqlite')->table('users')->where('email', 'admin@example.com')->first();

        expect(Schema::connection('sqlite')->hasTable('users'))->toBeTrue()
            ->and(Schema::connection('sqlite')->hasTable('signing_keys'))->toBeTrue()
            ->and(DB::connection('sqlite')->table('users')->count())->toBe(1)
            ->and(DB::connection('sqlite')->table('signing_keys')->whereNull('retired_at')->count())->toBe(1)
            ->and($administrator)->not->toBeNull()
            ->and($administrator->first_name)->toBe('Ada')
            ->and($administrator->last_name)->toBe('Lovelace')
            ->and((bool) $administrator->is_instance_admin)->toBeTrue()
            ->and((bool) $administrator->is_active)->toBeTrue()
            ->and(is_string($administrator->password) && Hash::check('password', $administrator->password))->toBeTrue()
            ->and(File::exists(Passport::keyPath('oauth-private.key')))->toBeTrue()
            ->and(File::get($envPath))->toContain('APP_NAME=Signaculum')
            ->and(File::get($envPath))->toContain('DB_CONNECTION=sqlite');
    } finally {
        putenv('SIGNACULUM_ADMIN_EMAIL');
        putenv('SIGNACULUM_ADMIN_PASSWORD');
        putenv('SIGNACULUM_ADMIN_FIRST_NAME');
        putenv('SIGNACULUM_ADMIN_LAST_NAME');
        unset(
            $_ENV['SIGNACULUM_ADMIN_EMAIL'],
            $_ENV['SIGNACULUM_ADMIN_PASSWORD'],
            $_ENV['SIGNACULUM_ADMIN_FIRST_NAME'],
            $_ENV['SIGNACULUM_ADMIN_LAST_NAME'],
        );
        restoreInstallEnv($envPath, $envBackup);
    }
});

test('install prints a generated administrator password when none is provided', function () {
    [$envPath, $envBackup] = beginInstallFixtures();
    File::delete(Passport::keyPath('oauth-private.key'));
    File::delete(Passport::keyPath('oauth-public.key'));
    putenv('SIGNACULUM_ADMIN_EMAIL=generated@example.com');
    $_ENV['SIGNACULUM_ADMIN_EMAIL'] = 'generated@example.com';
    putenv('SIGNACULUM_ADMIN_PASSWORD');
    unset($_ENV['SIGNACULUM_ADMIN_PASSWORD']);

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Administrator password (shown once):')
            ->assertSuccessful();

        config([
            'database.connections.sqlite.database' => database_path('database.sqlite'),
        ]);
        DB::purge('sqlite');

        expect(DB::connection('sqlite')->table('users')->where('email', 'generated@example.com')->exists())->toBeTrue();
    } finally {
        putenv('SIGNACULUM_ADMIN_EMAIL');
        unset($_ENV['SIGNACULUM_ADMIN_EMAIL']);
        restoreInstallEnv($envPath, $envBackup);
    }
});

test('install refuses a production APP_ENV in an existing .env without --force', function () {
    $path = base_path('.env');
    $backup = File::exists($path) ? File::get($path) : null;
    File::put($path, "APP_ENV=production\nAPP_KEY=\n");

    try {
        $this->artisan(InstallCommand::class, [
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Refusing: APP_ENV looks like production')
            ->assertFailed();
    } finally {
        restoreInstallEnv($path, $backup);
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

test('install env helpers escape values and apply mysql credentials', function () {
    $command = app(InstallCommand::class);
    $setEnv = new ReflectionMethod(InstallCommand::class, 'setEnv');
    $apply = new ReflectionMethod(InstallCommand::class, 'applyDatabaseCredentials');
    $unwrap = new ReflectionMethod(InstallCommand::class, 'unwrapEnvValue');
    $isValid = new ReflectionMethod(InstallCommand::class, 'isValidAppKey');

    $setEnv->setAccessible(true);
    $apply->setAccessible(true);
    $unwrap->setAccessible(true);
    $isValid->setAccessible(true);

    $env = "APP_NAME=Old\nDB_CONNECTION=sqlite\n";
    $env = $setEnv->invoke($command, $env, 'APP_NAME', 'Signaculum Core');
    $env = $apply->invoke($command, $env, 'mysql', [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'signaculum',
        'username' => 'root',
        'password' => 's3cret "quote"',
    ]);

    expect($env)->toContain('APP_NAME="Signaculum Core"')
        ->and($env)->toContain('DB_HOST=127.0.0.1')
        ->and($env)->toContain('DB_DATABASE=signaculum')
        ->and($unwrap->invoke($command, '"hello\\"world"'))->toBe('hello"world')
        ->and($unwrap->invoke($command, "'plain'"))->toBe('plain')
        ->and($unwrap->invoke($command, 'bare'))->toBe('bare')
        ->and($isValid->invoke($command, 'base64:abc'))->toBeTrue()
        ->and($isValid->invoke($command, ''))->toBeFalse()
        ->and($apply->invoke($command, $env, 'sqlite', []))->toBe($env);
});

test('install helpers escape empty values and detect a missing production env', function () {
    $command = app(InstallCommand::class);
    $escape = new ReflectionMethod(InstallCommand::class, 'escapeEnvValue');
    $looksLikeProduction = new ReflectionMethod(InstallCommand::class, 'looksLikeProduction');
    $environmentValue = new ReflectionMethod(InstallCommand::class, 'environmentValue');

    $escape->setAccessible(true);
    $looksLikeProduction->setAccessible(true);
    $environmentValue->setAccessible(true);

    $path = base_path('.env');
    $backup = File::exists($path) ? File::get($path) : null;
    File::delete($path);

    try {
        expect($escape->invoke($command, ''))->toBe('""')
            ->and($escape->invoke($command, 'plain'))->toBe('plain')
            ->and($escape->invoke($command, 'hash#tag'))->toBe('"hash#tag"')
            ->and($looksLikeProduction->invoke($command))->toBeFalse()
            ->and($environmentValue->invoke($command, 'SIGNACULUM_MISSING_KEY', 'fallback'))->toBe('fallback');
    } finally {
        restoreInstallEnv($path, $backup);
    }
});
