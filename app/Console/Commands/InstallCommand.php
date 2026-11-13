<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SigningKey;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * First-run installer: `.env`, database, Passport keys, an id_token signing key
 * and the first administrator.
 *
 * `composer create-project` only copies `.env` and generates `APP_KEY`. This command
 * is the step that migrates. Non-interactive CI passes `--no-interaction --force`
 * and always uses SQLite.
 *
 * ponytail: non-interactive installs are SQLite only; ceiling: a scripted MySQL or
 * PostgreSQL install; upgrade: read a complete DB_* set from the environment.
 */
#[Signature('signaculum:install {--force : Overwrite .env and allow a production-looking environment} {--no-npm : Skip npm install and the frontend build}')]
#[Description('Write .env, migrate, create Passport and signing keys, and the first administrator')]
final class InstallCommand extends Command
{
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        if ($this->looksLikeProduction() && ! $force) {
            $this->components->error('Refusing: APP_ENV looks like production. Pass --force if you really mean it.');

            return self::FAILURE;
        }

        if (! File::exists(base_path('.env.example'))) {
            $this->components->error('.env.example is missing.');

            return self::FAILURE;
        }

        $envPath = base_path('.env');

        if (File::exists($envPath) && ! $force) {
            if ($this->isNonInteractive()) {
                $this->components->error('.env already exists. Pass --force to overwrite it.');

                return self::FAILURE;
            }

            if (! confirm('.env already exists. Overwrite?', default: false)) {
                $this->components->warn('Aborted. Left the existing .env untouched.');

                return self::FAILURE;
            }
        }

        $appName = $this->askForApplicationName();
        $appUrl = $this->askForApplicationUrl();
        $connection = $this->askForDatabaseConnection();
        $databaseCredentials = $connection === 'sqlite' ? [] : $this->askForDatabaseCredentials($connection);

        $preservedAppKey = $this->resolvePreservedAppKey($envPath);
        $env = File::get(base_path('.env.example'));
        $env = $this->setEnv($env, 'APP_NAME', $appName);
        $env = $this->setEnv($env, 'APP_URL', $appUrl);
        $env = $this->setEnv($env, 'DB_CONNECTION', $connection);
        $env = $this->applyDatabaseCredentials($env, $connection, $databaseCredentials);

        if ($preservedAppKey !== null) {
            $env = $this->setEnv($env, 'APP_KEY', $preservedAppKey);
        }

        File::put($envPath, $env);
        $this->components->info('Wrote .env');

        if ($connection === 'sqlite') {
            $sqlitePath = database_path('database.sqlite');

            if (! File::exists($sqlitePath)) {
                File::put($sqlitePath, '');
            }
        }

        $this->ensureApplicationKey($envPath, $preservedAppKey);
        $this->useInstallConnection($connection);

        $migrate = $this->runArtisanProcess($connection, ['migrate', '--force', '--no-interaction']);

        if ($migrate !== self::SUCCESS) {
            return $migrate;
        }

        if (! File::exists(storage_path('oauth-private.key'))) {
            $passportKeys = $this->runArtisanProcess($connection, ['passport:keys', '--force', '--no-interaction']);

            if ($passportKeys !== self::SUCCESS) {
                return $passportKeys;
            }
        }

        $this->useInstallConnection($connection);

        if (! SigningKey::query()->active()->exists()) {
            $signingKey = $this->runArtisanProcess($connection, ['signaculum:rotate-signing-key', '--no-interaction']);

            if ($signingKey !== self::SUCCESS) {
                return $signingKey;
            }
        }

        $this->useInstallConnection($connection);
        $administrator = $this->createFirstAdministrator();

        if ($administrator !== self::SUCCESS) {
            return $administrator;
        }

        $this->call('storage:link', ['--force' => true]);

        if (! $this->option('no-npm')) {
            $frontend = $this->buildFrontend();

            if ($frontend !== self::SUCCESS) {
                return $frontend;
            }
        }

        $this->newLine();
        $this->components->info('Install complete.');
        $this->line('Next: php artisan signaculum:upgrade after each later update. Do not run create-project again.');

        return self::SUCCESS;
    }

    private function askForApplicationName(): string
    {
        if ($this->isNonInteractive()) {
            return $this->environmentValue('APP_NAME', 'Signaculum');
        }

        return text('Application name', default: 'Signaculum', required: true);
    }

    private function askForApplicationUrl(): string
    {
        if ($this->isNonInteractive()) {
            return $this->environmentValue('APP_URL', 'http://localhost');
        }

        return text('Application URL', default: 'http://localhost', required: true);
    }

    private function askForDatabaseConnection(): string
    {
        if ($this->isNonInteractive()) {
            return 'sqlite';
        }

        $connection = select('Database', [
            'sqlite' => 'SQLite (local default)',
            'pgsql' => 'PostgreSQL',
            'mysql' => 'MySQL 8+',
            'mariadb' => 'MariaDB',
        ], default: 'sqlite');

        return is_string($connection) ? $connection : 'sqlite';
    }

    /**
     * @return array{host: string, port: string, database: string, username: string, password: string}
     */
    private function askForDatabaseCredentials(string $connection): array
    {
        return [
            'host' => text('DB_HOST', default: '127.0.0.1', required: true),
            'port' => text('DB_PORT', default: $connection === 'pgsql' ? '5432' : '3306', required: true),
            'database' => text('DB_DATABASE', default: 'signaculum', required: true),
            'username' => text('DB_USERNAME', default: 'root', required: true),
            'password' => text('DB_PASSWORD', default: ''),
        ];
    }

    /**
     * @param  array{host?: string, port?: string, database?: string, username?: string, password?: string}  $databaseCredentials
     */
    private function applyDatabaseCredentials(string $env, string $connection, array $databaseCredentials): string
    {
        if ($connection === 'sqlite') {
            return $env;
        }

        $env = $this->setEnv($env, 'DB_HOST', $databaseCredentials['host'] ?? '127.0.0.1');
        $env = $this->setEnv($env, 'DB_PORT', $databaseCredentials['port'] ?? '');
        $env = $this->setEnv($env, 'DB_DATABASE', $databaseCredentials['database'] ?? 'signaculum');
        $env = $this->setEnv($env, 'DB_USERNAME', $databaseCredentials['username'] ?? '');

        return $this->setEnv($env, 'DB_PASSWORD', $databaseCredentials['password'] ?? '');
    }

    /**
     * Keep a valid APP_KEY so a re-run does not invalidate encrypted signing keys.
     */
    private function resolvePreservedAppKey(string $envPath): ?string
    {
        if (File::exists($envPath)) {
            $existing = File::get($envPath);

            if (preg_match('/^APP_KEY=(.*)$/m', $existing, $matches) === 1) {
                $fromFile = $this->unwrapEnvValue(trim($matches[1]));

                if ($this->isValidAppKey($fromFile)) {
                    return $fromFile;
                }
            }
        }

        $fromConfig = (string) config('app.key');

        if ($this->isValidAppKey($fromConfig)) {
            return $fromConfig;
        }

        return null;
    }

    private function ensureApplicationKey(string $envPath, ?string $preservedAppKey): void
    {
        if ($preservedAppKey !== null) {
            config(['app.key' => $preservedAppKey]);
            $this->line('Preserved the existing APP_KEY.');

            return;
        }

        $key = 'base64:'.base64_encode(
            Encrypter::generateKey((string) config('app.cipher')),
        );
        $env = File::get($envPath);
        File::put($envPath, $this->setEnv($env, 'APP_KEY', $key));
        config(['app.key' => $key]);
        $this->components->info('Application key set.');
    }

    /**
     * Point the already-booted process at the database this install just selected.
     * Tests boot on `:memory:`; the installer writes `database/database.sqlite`.
     */
    private function useInstallConnection(string $connection): void
    {
        config(['database.default' => $connection]);

        if ($connection === 'sqlite') {
            config([
                'database.connections.sqlite.database' => database_path('database.sqlite'),
            ]);
        }

        DB::purge($connection);
    }

    /**
     * Creates the first sign-in account as an instance admin (`is_instance_admin`).
     */
    private function createFirstAdministrator(): int
    {
        if (User::query()->exists()) {
            $this->line('An account already exists. Skipped the first administrator.');

            return self::SUCCESS;
        }

        $email = $this->isNonInteractive()
            ? $this->environmentValue('SIGNACULUM_ADMIN_EMAIL', 'admin@example.com')
            : text('Administrator email', default: 'admin@example.com', required: true);
        $firstName = $this->isNonInteractive()
            ? $this->environmentValue('SIGNACULUM_ADMIN_FIRST_NAME', $this->environmentValue('SIGNACULUM_ADMIN_NAME', 'Administrator'))
            : text('Administrator first name', default: 'Administrator', required: true);
        $lastName = $this->isNonInteractive()
            ? $this->environmentValue('SIGNACULUM_ADMIN_LAST_NAME', '')
            : text('Administrator last name', default: '');
        $passwordFromEnvironment = $this->environmentValue('SIGNACULUM_ADMIN_PASSWORD', '');
        $shouldPrintPassword = false;

        if ($passwordFromEnvironment !== '') {
            $password = $passwordFromEnvironment;
        } elseif ($this->isNonInteractive()) {
            $password = Str::password(24);
            $shouldPrintPassword = true;
        } else {
            $password = password('Administrator password', required: true);
        }

        $administrator = (new User)->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName !== '' ? $lastName : null,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'is_instance_admin' => true,
            'email_verified_at' => now(),
        ]);
        $administrator->save();

        $this->components->info("Created the first administrator [{$email}].");

        if ($shouldPrintPassword) {
            $this->components->warn('Administrator password (shown once): '.$password);
        }

        return self::SUCCESS;
    }

    private function buildFrontend(): int
    {
        $this->components->info('npm install…');
        $install = Process::path(base_path())->timeout(600)->run(['npm', 'install']);

        if (! $install->successful()) {
            $this->components->error($install->errorOutput() !== '' ? $install->errorOutput() : $install->output());

            return self::FAILURE;
        }

        Process::path(base_path())->timeout(120)->run([
            PHP_BINARY, 'artisan', 'wayfinder:generate', '--with-form', '--no-interaction',
        ]);

        $this->components->info('npm run build…');
        $build = Process::path(base_path())->timeout(600)->run(['npm', 'run', 'build']);

        if (! $build->successful()) {
            $this->components->error($build->errorOutput() !== '' ? $build->errorOutput() : $build->output());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $artisanArguments
     */
    private function runArtisanProcess(string $connection, array $artisanArguments): int
    {
        $result = Process::path(base_path())
            ->timeout(600)
            ->env($this->databaseProcessEnvironment($connection))
            ->run([PHP_BINARY, 'artisan', ...$artisanArguments]);

        if ($result->output() !== '') {
            $this->output->write($result->output());
        }

        if (! $result->successful()) {
            $this->components->error($result->errorOutput() !== '' ? $result->errorOutput() : $result->output());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function databaseProcessEnvironment(string $connection): array
    {
        $config = config('database.connections.'.$connection, []);
        $appKey = (string) config('app.key');
        $environment = [
            'APP_ENV' => 'local',
            'DB_CONNECTION' => $connection,
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];

        if ($appKey !== '') {
            $environment['APP_KEY'] = $appKey;
        }

        if ($connection === 'sqlite') {
            $environment['DB_DATABASE'] = (string) ($config['database'] ?? database_path('database.sqlite'));

            return $environment;
        }

        $environment['DB_HOST'] = (string) ($config['host'] ?? '127.0.0.1');
        $environment['DB_PORT'] = (string) ($config['port'] ?? '');
        $environment['DB_DATABASE'] = (string) ($config['database'] ?? '');
        $environment['DB_USERNAME'] = (string) ($config['username'] ?? '');
        $environment['DB_PASSWORD'] = (string) ($config['password'] ?? '');

        return $environment;
    }

    private function looksLikeProduction(): bool
    {
        if (app()->environment('production')) {
            return true;
        }

        if (! File::exists(base_path('.env'))) {
            return false;
        }

        return preg_match('/^APP_ENV\s*=\s*production\s*$/mi', File::get(base_path('.env'))) === 1;
    }

    private function isNonInteractive(): bool
    {
        return ! $this->input->isInteractive();
    }

    private function environmentValue(string $key, string $default): string
    {
        $value = getenv($key);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private function setEnv(string $env, string $key, string $value): string
    {
        $escaped = $this->escapeEnvValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $env) === 1) {
            return preg_replace($pattern, $key.'='.$escaped, $env) ?? $env;
        }

        return rtrim($env)."\n{$key}={$escaped}\n";
    }

    private function escapeEnvValue(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\']/', $value) === 1) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
        }

        return $value;
    }

    private function unwrapEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function isValidAppKey(string $key): bool
    {
        return $key !== '' && str_starts_with($key, 'base64:');
    }
}
