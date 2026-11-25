<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Actions\CreateOrganizationAction;
use App\Actions\DeleteOrganizationAction;
use App\Data\OrganizationData;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A real two-session race on organisation offboarding (#289): a second PHP process with its
 * own database connection adds a member, through a model it resolved before the delete, while
 * this process is inside the offboarding transaction. Both lock the organisation row, so the
 * add waits for the delete to commit and then refuses the deleted organisation; no live
 * membership or active principal is left on it.
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

test('a member added while the organisation is being offboarded is refused', function () {
    $owner = User::factory()->create();
    $latecomer = User::factory()->create();
    $organization = app(CreateOrganizationAction::class)->execute(new OrganizationData('Racing Corp', 'racing-corp'), $owner);
    $staleOrganization = Organization::query()->findOrFail($organization->getKey());
    $outcomeFile = tempnam(sys_get_temp_dir(), 'signaculum-offboarding-race-');

    $hasForked = false;
    $forkedProcessId = null;

    // Pause the offboarding once it removes the memberships, and add a member from another session.
    DB::listen(function (QueryExecuted $query) use (&$hasForked, &$forkedProcessId, $staleOrganization, $latecomer, $outcomeFile): void {
        if ($hasForked || ! str_starts_with(strtolower($query->sql), 'delete') || ! str_contains($query->sql, 'organization_user')) {
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
                app(AddOrganizationMemberAction::class)->execute($staleOrganization, $latecomer, [OrganizationRoleEnum::Member->value]);
                file_put_contents($outcomeFile, 'added');
            } catch (ValidationException) {
                file_put_contents($outcomeFile, 'refused');
            } catch (Throwable $exception) {
                file_put_contents($outcomeFile, 'error: '.$exception->getMessage());
            } finally {
                // Skip shutdown and destructors, which would touch the parent's socket.
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        sleep(3);
    });

    app(DeleteOrganizationAction::class)->execute($organization);

    expect($forkedProcessId)->toBeInt()->toBeGreaterThan(0);

    pcntl_waitpid($forkedProcessId, $forkedProcessStatus);
    $outcome = (string) file_get_contents($outcomeFile);
    unlink($outcomeFile);

    expect($outcome)->toBe('refused')
        ->and(OrganizationMembership::query()->where('organization_id', $organization->getKey())->exists())->toBeFalse()
        ->and(Principal::query()->where('organisation_id', $organization->getKey())->whereNull('deactivated_at')->exists())->toBeFalse();
});
