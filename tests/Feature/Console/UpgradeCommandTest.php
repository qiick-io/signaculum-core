<?php

declare(strict_types=1);

use App\Console\Commands\UpgradeCommand;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

test('signaculum:upgrade is registered', function () {
    expect(Artisan::all())->toHaveKey('signaculum:upgrade');
});

test('upgrade refuses production without --force', function () {
    $this->app['env'] = 'production';

    $this->artisan(UpgradeCommand::class)
        ->expectsOutputToContain('Refusing to run in production without --force.')
        ->assertFailed();
});

test('upgrade clears caches with --force and without migrating', function () {
    $this->artisan(UpgradeCommand::class, [
        '--force' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Upgrade complete.')
        ->assertSuccessful();
});

test('upgrade refuses --dry-run without --sync-upstream', function () {
    $this->artisan(UpgradeCommand::class, [
        '--dry-run' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('--dry-run requires --sync-upstream')
        ->assertFailed();
});

test('upgrade refuses --sync-upstream when this is not a git repository', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result(output: "false\n", exitCode: 128),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('not a git repository')
        ->assertFailed();
});

test('upgrade dry-runs --sync-upstream without merging', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'diff --name-status HEAD v1.0.0' => Process::result("M\tREADME.md\n"),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Dry-run only')
        ->assertSuccessful();

    Process::assertRanTimes(fn (PendingProcess $process): bool => implode(' ', upgradeGitArguments($process)) === 'merge --no-edit v1.0.0', 0);
});

test('upgrade refuses a dirty tree without --force', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(" M README.md\n"),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Git working tree is dirty')
        ->assertFailed();
});

test('upgrade refuses --sync-upstream with a dirty tree without --force', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(" M README.md\n"),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Git working tree is dirty')
        ->assertFailed();
});

test('upgrade reports a failed git fetch during --sync-upstream', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(errorOutput: 'network down', exitCode: 1),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('git fetch failed')
        ->assertFailed();
});

test('upgrade reports a missing tag after fetch', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v9.9.9' => Process::result(errorOutput: 'not found', exitCode: 1),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v9.9.9',
        '--dry-run' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Tag not found after fetch')
        ->assertFailed();
});

test('upgrade dry-run reports when HEAD already matches the tag', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'diff --name-status HEAD v1.0.0' => Process::result(''),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--dry-run' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('No file differences')
        ->assertSuccessful();
});

test('upgrade adds the upstream remote when it is missing then merges with --yes', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\n"),
        'remote add upstream https://github.com/qiick-io/signaculum-core.git' => Process::result(''),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'diff --name-status HEAD v1.0.0' => Process::result("M\tREADME.md\n"),
        'merge --no-edit v1.0.0' => Process::result(''),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--yes' => true,
        '--force' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Synced tag v1.0.0')
        ->assertSuccessful();
});

test('upgrade reports a failed merge during --sync-upstream', function () {
    fakeUpgradeGit([
        'rev-parse --is-inside-work-tree' => Process::result("true\n"),
        'status --porcelain' => Process::result(''),
        'remote' => Process::result("origin\nupstream\n"),
        'fetch --tags upstream' => Process::result(''),
        'rev-parse --verify refs/tags/v1.0.0' => Process::result("abc123\n"),
        'diff --name-status HEAD v1.0.0' => Process::result("M\tREADME.md\n"),
        'merge --no-edit v1.0.0' => Process::result(errorOutput: 'CONFLICT', exitCode: 1),
    ]);

    $this->artisan(UpgradeCommand::class, [
        '--sync-upstream' => 'v1.0.0',
        '--yes' => true,
        '--force' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Merge failed')
        ->assertFailed();
});

test('upgrade refuses --sync-upstream without a tag value', function () {
    $this->artisan('signaculum:upgrade', [
        '--sync-upstream' => '',
        '--force' => true,
        '--no-migrate' => true,
    ])
        ->expectsOutputToContain('Pass a release tag')
        ->assertFailed();
});

/**
 * @param  array<string, ProcessResult>  $map
 */
function fakeUpgradeGit(array $map): void
{
    Process::preventStrayProcesses();
    Process::fake(function (PendingProcess $process) use ($map): ProcessResult {
        $key = implode(' ', upgradeGitArguments($process));

        if (! array_key_exists($key, $map)) {
            throw new RuntimeException('Unexpected git invocation: git '.$key);
        }

        return $map[$key];
    });
}

/**
 * @return list<string>
 */
function upgradeGitArguments(PendingProcess $process): array
{
    $command = $process->command;

    if (is_string($command)) {
        $parts = preg_split('/\s+/', trim($command)) ?: [];

        return array_values(array_slice($parts, 1));
    }

    return array_values(array_slice($command, 1));
}
