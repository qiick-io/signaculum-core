<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Actions\CreateOrganizationAction;
use App\Data\OrganizationData;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * A real two-session race: the same user joins two organisations at once, by being added or by
 * creating them. Inserting a
 * membership takes a key-share lock on the user row (the foreign key), and the principal then
 * locks that row for update; without taking the user lock first, two adds each hold the
 * key-share lock the other's update waits for, and PostgreSQL aborts one as a deadlock.
 *
 * Runs on PostgreSQL and MySQL only: SQLite has a single writer, so it cannot race.
 */
beforeEach(function () {
    if (! in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true) || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs PostgreSQL or MySQL row locks and pcntl.');
    }
});

// DatabaseTruncation empties the tables before each test only: leave no committed rows to the suites after this one.
afterEach(function () {
    if (in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->truncateDatabaseTables();
    }
});

test('two organisations adding the same user at once both succeed', function () {
    $member = User::factory()->create();
    $firstOrganization = Organization::factory()->create();
    $secondOrganization = Organization::factory()->create();
    $outcomeFile = tempnam(sys_get_temp_dir(), 'signaculum-membership-race-');

    $hasForked = false;
    $forkedProcessId = null;

    // Pause the first add once its membership row is inserted, and add the same user elsewhere.
    DB::listen(function (QueryExecuted $query) use (&$hasForked, &$forkedProcessId, $secondOrganization, $member, $outcomeFile): void {
        if ($hasForked || ! str_starts_with(strtolower($query->sql), 'insert') || ! str_contains($query->sql, 'organization_user')) {
            return;
        }

        $hasForked = true;
        $forkedProcessId = pcntl_fork();

        if ($forkedProcessId === 0) {
            // Keep the inherited connection object alive: closing it would end the
            // parent's session, which shares the socket.
            $GLOBALS['inheritedDatabaseConnection'] = DB::connection()->getPdo();
            DB::connection()->setPdo(null)->setReadPdo(null);
            DB::reconnect();

            try {
                app(AddOrganizationMemberAction::class)->execute($secondOrganization, $member, [OrganizationRoleEnum::Member->value]);
                file_put_contents($outcomeFile, 'added');
            } catch (Throwable $exception) {
                file_put_contents($outcomeFile, 'error: '.$exception->getMessage());
            } finally {
                // Skip shutdown and destructors, which would touch the parent's socket.
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        sleep(2);
    });

    $parentOutcome = 'added';

    try {
        app(AddOrganizationMemberAction::class)->execute($firstOrganization, $member, [OrganizationRoleEnum::Member->value]);
    } catch (Throwable $exception) {
        $parentOutcome = 'error: '.$exception->getMessage();
    }

    expect($forkedProcessId)->toBeInt()->toBeGreaterThan(0);

    pcntl_waitpid($forkedProcessId, $forkedProcessStatus);
    $childOutcome = (string) file_get_contents($outcomeFile);
    unlink($outcomeFile);

    expect([$parentOutcome, $childOutcome])->toBe(['added', 'added'])
        ->and(OrganizationMembership::query()->where('user_id', $member->getKey())->count())->toBe(2)
        ->and(Principal::query()->where('user_id', $member->getKey())->whereNull('deactivated_at')->count())->toBe(2);
});

test('one user creating two organisations at once gets both', function () {
    $creator = User::factory()->create();
    $outcomeFile = tempnam(sys_get_temp_dir(), 'signaculum-organization-race-');

    $hasForked = false;
    $forkedProcessId = null;

    // Pause the first creation once its owner membership is inserted, and create a second one.
    DB::listen(function (QueryExecuted $query) use (&$hasForked, &$forkedProcessId, $creator, $outcomeFile): void {
        if ($hasForked || ! str_starts_with(strtolower($query->sql), 'insert') || ! str_contains($query->sql, 'organization_user')) {
            return;
        }

        $hasForked = true;
        $forkedProcessId = pcntl_fork();

        if ($forkedProcessId === 0) {
            $GLOBALS['inheritedDatabaseConnection'] = DB::connection()->getPdo();
            DB::connection()->setPdo(null)->setReadPdo(null);
            DB::reconnect();

            try {
                app(CreateOrganizationAction::class)->execute(new OrganizationData('Second Corp', 'second-corp'), $creator);
                file_put_contents($outcomeFile, 'created');
            } catch (Throwable $exception) {
                file_put_contents($outcomeFile, 'error: '.$exception->getMessage());
            } finally {
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        sleep(2);
    });

    $parentOutcome = 'created';

    try {
        app(CreateOrganizationAction::class)->execute(new OrganizationData('First Corp', 'first-corp'), $creator);
    } catch (Throwable $exception) {
        $parentOutcome = 'error: '.$exception->getMessage();
    }

    expect($forkedProcessId)->toBeInt()->toBeGreaterThan(0);

    pcntl_waitpid($forkedProcessId, $forkedProcessStatus);
    $childOutcome = (string) file_get_contents($outcomeFile);
    unlink($outcomeFile);

    expect([$parentOutcome, $childOutcome])->toBe(['created', 'created'])
        ->and(OrganizationMembership::query()->where('user_id', $creator->getKey())->count())->toBe(2);
});
