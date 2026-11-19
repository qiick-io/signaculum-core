<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Post-update steps for a tree that already has the new code and its dependencies.
 *
 * Does not run Composer or npm. `--sync-upstream` is opt-in and can overwrite
 * local files with a tag from the official repository.
 */
#[Signature('signaculum:upgrade {--force : Allow production and a dirty git working tree} {--no-migrate : Skip migrations} {--sync-upstream= : Merge a release tag from the official repository} {--dry-run : With --sync-upstream, list files and do not merge} {--yes : Skip the confirmation before merging upstream}')]
#[Description('Migrate and clear caches after an update')]
final class UpgradeCommand extends Command
{
    public const string UPSTREAM_REMOTE = 'upstream';

    public const string UPSTREAM_URL = 'https://github.com/qiick-io/signaculum-core.git';

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $syncTag = $this->normalizedSyncTag();
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun && $syncTag === null) {
            $this->components->error('--dry-run requires --sync-upstream=vX.Y.Z.');

            return self::FAILURE;
        }

        if ($this->syncFlagPresentWithoutTag()) {
            $this->components->error('Pass a release tag: --sync-upstream=vX.Y.Z');

            return self::FAILURE;
        }

        if (app()->environment('production') && ! $force) {
            $this->components->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }

        if ($syncTag !== null) {
            $sync = $this->syncFromUpstream($syncTag, $isDryRun, $force);

            if ($sync !== self::SUCCESS) {
                return $sync;
            }

            if ($isDryRun) {
                return self::SUCCESS;
            }
        } elseif (! $force && $this->gitTreeIsDirty()) {
            $this->components->error('Git working tree is dirty. Commit or stash changes, or pass --force.');

            return self::FAILURE;
        }

        $this->components->warn('signaculum:upgrade does not run Composer or npm.');
        $this->line('After the new code is in place: composer install --no-dev --optimize-autoloader && npm ci && npm run build');
        $this->newLine();

        if (! $this->option('no-migrate')) {
            $this->components->info('Running migrations…');
            $code = $this->call('migrate', ['--force' => true]);

            if ($code !== self::SUCCESS) {
                return $code;
            }
        }

        $this->components->info('Syncing permissions…');
        $syncCode = $this->call('signaculum:sync-permissions');

        if ($syncCode !== self::SUCCESS) {
            return $syncCode;
        }

        $this->runVersionSteps();

        $this->components->info('Clearing caches…');
        $this->call('optimize:clear');

        $this->newLine();
        $this->components->info('Upgrade complete.');

        return self::SUCCESS;
    }

    /**
     * ponytail: no version-specific data steps until the first tagged release;
     * ceiling: the first upgrade that must transform existing rows;
     * upgrade: branch on the previously installed version here.
     */
    private function runVersionSteps(): void {}

    private function syncFromUpstream(string $tag, bool $isDryRun, bool $force): int
    {
        $this->components->warn('--sync-upstream may overwrite local files.');
        $this->newLine();

        if (! $this->isGitRepository()) {
            $this->components->error('Refusing --sync-upstream: not a git repository.');

            return self::FAILURE;
        }

        if ($this->gitTreeIsDirty() && ! $force) {
            $this->components->error('Git working tree is dirty. Commit or stash changes, or pass --force.');

            return self::FAILURE;
        }

        if (! $this->ensureUpstreamRemote()) {
            return self::FAILURE;
        }

        $fetch = $this->git(['fetch', '--tags', self::UPSTREAM_REMOTE]);

        if (! $fetch->successful()) {
            $this->components->error('git fetch failed: '.trim($fetch->errorOutput() !== '' ? $fetch->errorOutput() : $fetch->output()));

            return self::FAILURE;
        }

        $verify = $this->git(['rev-parse', '--verify', 'refs/tags/'.$tag]);

        if (! $verify->successful()) {
            $this->components->error("Tag not found after fetch: {$tag}.");

            return self::FAILURE;
        }

        $diff = $this->git(['diff', '--name-status', 'HEAD', $tag]);

        if (! $diff->successful()) {
            $this->components->error('Could not diff HEAD against '.$tag.'.');

            return self::FAILURE;
        }

        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $diff->output()))));
        $count = count($lines);

        if ($count === 0) {
            $this->components->info("No file differences between HEAD and {$tag}.");
        } else {
            $this->components->info("{$count} file(s) differ from {$tag}:");

            foreach ($lines as $line) {
                $this->line('  '.$line);
            }
        }

        if ($isDryRun) {
            $this->newLine();
            $this->components->info('Dry-run only. No merge and no migrate.');

            return self::SUCCESS;
        }

        if ($count > 0 && ! $this->option('yes') && ! $this->confirm("{$count} files will change. Continue?", false)) {
            $this->components->warn('Aborted. Left the working tree untouched.');

            return self::FAILURE;
        }

        $merge = $this->git(['merge', '--no-edit', $tag]);

        if (! $merge->successful()) {
            $this->components->error('Merge failed. Resolve it before migrating.');
            $detail = trim($merge->errorOutput() !== '' ? $merge->errorOutput() : $merge->output());

            if ($detail !== '') {
                $this->line($detail);
            }

            return self::FAILURE;
        }

        $this->components->info('Synced tag '.$tag.' from '.self::UPSTREAM_REMOTE.'.');

        return self::SUCCESS;
    }

    private function ensureUpstreamRemote(): bool
    {
        $remotes = $this->git(['remote']);

        if (! $remotes->successful()) {
            $this->components->error('Could not list git remotes.');

            return false;
        }

        $names = array_filter(array_map(trim(...), explode("\n", $remotes->output())));

        if (in_array(self::UPSTREAM_REMOTE, $names, true)) {
            return true;
        }

        $this->line('Adding remote '.self::UPSTREAM_REMOTE.' → '.self::UPSTREAM_URL);
        $add = $this->git(['remote', 'add', self::UPSTREAM_REMOTE, self::UPSTREAM_URL]);

        if (! $add->successful()) {
            $this->components->error('Failed to add the upstream remote.');

            return false;
        }

        return true;
    }

    private function normalizedSyncTag(): ?string
    {
        $raw = $this->option('sync-upstream');

        if (! is_string($raw)) {
            return null;
        }

        $tag = trim($raw);

        return $tag === '' ? null : $tag;
    }

    private function syncFlagPresentWithoutTag(): bool
    {
        if ($this->normalizedSyncTag() !== null) {
            return false;
        }

        return $this->input->hasParameterOption('--sync-upstream', true);
    }

    private function isGitRepository(): bool
    {
        $result = $this->git(['rev-parse', '--is-inside-work-tree']);

        return $result->successful() && trim($result->output()) === 'true';
    }

    private function gitTreeIsDirty(): bool
    {
        if (! $this->isGitRepository()) {
            return false;
        }

        $result = $this->git(['status', '--porcelain']);

        return $result->successful() && trim($result->output()) !== '';
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ProcessResult
    {
        return Process::path(base_path())->run(array_merge(['git'], $arguments));
    }
}
