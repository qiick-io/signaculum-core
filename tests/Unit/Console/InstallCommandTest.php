<?php

declare(strict_types=1);

use App\Console\Commands\InstallCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

/*
 * Parallel workers each get their own database but share storage/, so one Passport key pair
 * serves them all. Deleting it here made token signing fail in whichever worker raced it (#144).
 * The installer therefore writes its keys into a directory of this file's own.
 *
 * The same holds for the files the installer writes (#176): `.env` is shared by every parallel
 * worker and by the developer's own checkout, and `database/database.sqlite` is the developer's
 * local data. Each test points the application's environment path at a directory of its own,
 * with a copy of `.env.example`, and the guard in afterEach() fails the test when the real
 * `.env`, `.env.example` or `database/database.sqlite` changed in any byte.
 */
beforeEach(function () {
    $this->passportKeyDirectory = sys_get_temp_dir().'/signaculum-install-keys-'.Str::uuid();
    File::ensureDirectoryExists($this->passportKeyDirectory);
    Passport::loadKeysFrom($this->passportKeyDirectory);

    $this->realInstallFileFingerprints = installFileFingerprints();

    $this->installDirectory = sys_get_temp_dir().'/signaculum-install-'.Str::uuid();
    File::ensureDirectoryExists($this->installDirectory);
    File::copy(base_path('.env.example'), $this->installDirectory.'/.env.example');
    app()->useEnvironmentPath($this->installDirectory);
});

afterEach(function () {
    Passport::$keyPath = null;
    File::deleteDirectory($this->passportKeyDirectory);

    app()->useEnvironmentPath(base_path());
    File::deleteDirectory($this->installDirectory);
    clearInstallSqliteOverride();

    expect(installFileFingerprints())->toBe(
        $this->realInstallFileFingerprints,
        'The installer test changed a file of the real checkout (#176).',
    );
});

test('signaculum:install is registered', function () {
    expect(Artisan::all())->toHaveKey('signaculum:install');
});

test('install refuses a production .env without --force', function () {
    File::put(app()->environmentFilePath(), "APP_ENV=production\nAPP_KEY=\n");

    $this->artisan(InstallCommand::class)
        ->expectsOutputToContain('Refusing: APP_ENV looks like production')
        ->assertFailed();
});

test('install refuses when .env.example is missing', function () {
    File::delete($this->installDirectory.'/.env.example');

    $this->artisan(InstallCommand::class, [
        '--force' => true,
        '--no-npm' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('.env.example is missing')
        ->assertFailed();
});

test('install refuses an existing .env without --force in non-interactive mode', function () {
    File::put(app()->environmentFilePath(), "APP_ENV=local\nAPP_KEY=base64:dGVzdA==\n");

    $this->artisan(InstallCommand::class, [
        '--no-npm' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('.env already exists')
        ->assertFailed();
});

test('install skips creating an administrator when a user already exists', function () {
    $sqlitePath = useInstallSqliteDatabase($this->installDirectory);
    setInstallAdminEnvironment(
        email: 'admin@example.com',
        password: 'password',
        firstName: 'Ada',
        lastName: 'Lovelace',
    );

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        config([
            'database.connections.sqlite.database' => $sqlitePath,
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
        clearInstallAdminEnvironment();
    }
});

test('install migrates sqlite, creates keys and the first administrator', function () {
    $sqlitePath = useInstallSqliteDatabase($this->installDirectory);
    setInstallAdminEnvironment(
        email: 'admin@example.com',
        password: 'password',
        firstName: 'Ada',
        lastName: 'Lovelace',
    );

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('Install complete.')
            ->assertSuccessful();

        config([
            'database.connections.sqlite.database' => $sqlitePath,
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
            ->and(DB::connection('sqlite')->table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $administrator->id)
                ->where('roles.name', 'super-admin')
                ->exists())->toBeTrue()
            ->and((bool) $administrator->is_active)->toBeTrue()
            ->and(is_string($administrator->password) && Hash::check('password', $administrator->password))->toBeTrue()
            ->and(File::exists($this->passportKeyDirectory.'/oauth-private.key'))->toBeTrue()
            ->and(File::exists($this->passportKeyDirectory.'/oauth-public.key'))->toBeTrue()
            ->and(File::get(app()->environmentFilePath()))->toContain('APP_NAME=Signaculum')
            ->and(File::get(app()->environmentFilePath()))->toContain('DB_CONNECTION=sqlite')
            ->and(File::get(app()->environmentFilePath()))->toContain('DB_DATABASE='.$sqlitePath);
    } finally {
        clearInstallAdminEnvironment();
    }
});

test('install fails closed under --no-interaction when no password is provided', function () {
    $sqlitePath = useInstallSqliteDatabase($this->installDirectory);
    setInstallAdminEnvironment(
        email: 'generated@example.com',
        password: null,
        firstName: 'Generated',
        lastName: 'Admin',
    );

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('INITIAL_SUPER_ADMIN_PASSWORD is required')
            ->assertFailed();
    } finally {
        clearInstallAdminEnvironment();
    }
});

test('install refuses the known default password when APP_ENV is staging', function () {
    $sqlitePath = useInstallSqliteDatabase($this->installDirectory);
    setInstallAdminEnvironment(
        email: 'staging-admin@example.com',
        password: 'password',
        firstName: 'Staging',
        lastName: 'Admin',
    );
    putenv('SIGNACULUM_INSTALL_APP_ENV=staging');
    $_ENV['SIGNACULUM_INSTALL_APP_ENV'] = 'staging';
    $_SERVER['SIGNACULUM_INSTALL_APP_ENV'] = 'staging';

    try {
        $this->artisan(InstallCommand::class, [
            '--force' => true,
            '--no-npm' => true,
            '--no-interaction' => true,
        ])
            ->expectsOutputToContain('must not be the known default')
            ->assertFailed();
    } finally {
        putenv('SIGNACULUM_INSTALL_APP_ENV');
        unset($_ENV['SIGNACULUM_INSTALL_APP_ENV'], $_SERVER['SIGNACULUM_INSTALL_APP_ENV']);
        clearInstallAdminEnvironment();
    }
});

test('install refuses a production APP_ENV in an existing .env without --force', function () {
    File::put(app()->environmentFilePath(), "APP_ENV=production\nAPP_KEY=\n");

    $this->artisan(InstallCommand::class, [
        '--no-npm' => true,
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Refusing: APP_ENV looks like production')
        ->assertFailed();
});

/**
 * Points a non-interactive install at an empty SQLite file inside the test's own directory.
 */
function useInstallSqliteDatabase(string $installDirectory): string
{
    $sqlitePath = $installDirectory.'/database.sqlite';
    File::put($sqlitePath, '');
    putenv("SIGNACULUM_INSTALL_SQLITE={$sqlitePath}");
    $_ENV['SIGNACULUM_INSTALL_SQLITE'] = $sqlitePath;
    $_SERVER['SIGNACULUM_INSTALL_SQLITE'] = $sqlitePath;

    return $sqlitePath;
}

function clearInstallSqliteOverride(): void
{
    putenv('SIGNACULUM_INSTALL_SQLITE');
    unset($_ENV['SIGNACULUM_INSTALL_SQLITE'], $_SERVER['SIGNACULUM_INSTALL_SQLITE']);
}

/**
 * A hash of each real checkout file the installer could write, or null when it does not exist.
 *
 * @return array<string, string|null>
 */
function installFileFingerprints(): array
{
    $fingerprints = [];

    foreach ([base_path('.env'), base_path('.env.example'), database_path('database.sqlite')] as $path) {
        $fingerprints[$path] = File::exists($path) ? hash_file('sha256', $path) : null;
    }

    return $fingerprints;
}

/**
 * Drive `signaculum:install` credentials via INITIAL_SUPER_ADMIN_* (and the legacy
 * SIGNACULUM_ADMIN_* aliases). Clears Dotenv-loaded defaults from the process env
 * so a local `.env` with `password` cannot mask the test fixtures.
 */
function setInstallAdminEnvironment(
    string $email,
    ?string $password,
    string $firstName,
    string $lastName,
): void {
    $pairs = [
        'INITIAL_SUPER_ADMIN_EMAIL' => $email,
        'INITIAL_SUPER_ADMIN_FIRST_NAME' => $firstName,
        'INITIAL_SUPER_ADMIN_LAST_NAME' => $lastName,
        'SIGNACULUM_ADMIN_EMAIL' => $email,
        'SIGNACULUM_ADMIN_FIRST_NAME' => $firstName,
        'SIGNACULUM_ADMIN_LAST_NAME' => $lastName,
    ];

    foreach ($pairs as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    if ($password === null) {
        putenv('INITIAL_SUPER_ADMIN_PASSWORD');
        putenv('SIGNACULUM_ADMIN_PASSWORD');
        unset($_ENV['INITIAL_SUPER_ADMIN_PASSWORD'], $_ENV['SIGNACULUM_ADMIN_PASSWORD']);
        unset($_SERVER['INITIAL_SUPER_ADMIN_PASSWORD'], $_SERVER['SIGNACULUM_ADMIN_PASSWORD']);

        return;
    }

    putenv("INITIAL_SUPER_ADMIN_PASSWORD={$password}");
    putenv("SIGNACULUM_ADMIN_PASSWORD={$password}");
    $_ENV['INITIAL_SUPER_ADMIN_PASSWORD'] = $password;
    $_ENV['SIGNACULUM_ADMIN_PASSWORD'] = $password;
    $_SERVER['INITIAL_SUPER_ADMIN_PASSWORD'] = $password;
    $_SERVER['SIGNACULUM_ADMIN_PASSWORD'] = $password;
}

function clearInstallAdminEnvironment(): void
{
    foreach ([
        'INITIAL_SUPER_ADMIN_EMAIL',
        'INITIAL_SUPER_ADMIN_PASSWORD',
        'INITIAL_SUPER_ADMIN_FIRST_NAME',
        'INITIAL_SUPER_ADMIN_LAST_NAME',
        'SIGNACULUM_ADMIN_EMAIL',
        'SIGNACULUM_ADMIN_PASSWORD',
        'SIGNACULUM_ADMIN_FIRST_NAME',
        'SIGNACULUM_ADMIN_LAST_NAME',
        'SIGNACULUM_ADMIN_NAME',
        'SIGNACULUM_INSTALL_APP_ENV',
    ] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
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

    expect(File::exists(app()->environmentFilePath()))->toBeFalse()
        ->and($escape->invoke($command, ''))->toBe('""')
        ->and($escape->invoke($command, 'plain'))->toBe('plain')
        ->and($escape->invoke($command, 'hash#tag'))->toBe('"hash#tag"')
        ->and($looksLikeProduction->invoke($command))->toBeFalse()
        ->and($environmentValue->invoke($command, 'SIGNACULUM_MISSING_KEY', 'fallback'))->toBe('fallback');
});

test('install builds a mysql process environment for child artisan calls', function () {
    config([
        'app.key' => 'base64:dGVzdGtleQ==',
        'database.connections.mysql' => [
            'host' => 'db.example',
            'port' => '3306',
            'database' => 'signaculum',
            'username' => 'signaculum',
            'password' => 'secret',
        ],
    ]);

    $command = app(InstallCommand::class);
    $method = new ReflectionMethod(InstallCommand::class, 'databaseProcessEnvironment');
    $method->setAccessible(true);

    $environment = $method->invoke($command, 'mysql');

    expect($environment)->toMatchArray([
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'db.example',
        'DB_PORT' => '3306',
        'DB_DATABASE' => 'signaculum',
        'DB_USERNAME' => 'signaculum',
        'DB_PASSWORD' => 'secret',
        'APP_KEY' => 'base64:dGVzdGtleQ==',
    ]);
});
