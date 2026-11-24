<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * The token family transactions (the reuse revocation and the purge) are retried when the
 * database picks them as a deadlock victim, as MySQL and MariaDB may in REPEATABLE READ.
 *
 * The deadlock is simulated: the first matching statement fails with MySQL's deadlock
 * error. Laravel never retries a nested transaction, so these tests need the committed
 * rows of DatabaseTruncation instead of a wrapping test transaction; that truncation
 * cannot keep an in-memory SQLite database, so they run on PostgreSQL and MySQL.
 */
beforeEach(function () {
    if (! in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Needs a database that survives between connections.');
    }

    $this->user = User::factory()->create();
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
    $this->actingAs($this->user);
    $this->code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $this->issuedTokens = redeemCode($this->client, $this->code, $this->pkce['verifier'])->assertOk()->json();
    $this->refreshedTokens = refreshTokens($this, $this->issuedTokens['refresh_token'])->assertOk()->json();
});

afterEach(function () {
    if (in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->truncateDatabaseTables();
    }
});

/**
 * Fail the first statement that starts with `$statementStart` on `$table` with a deadlock.
 */
function failOnceWithDeadlock(string $statementStart, string $table): void
{
    $hasFailed = false;

    DB::listen(function (QueryExecuted $query) use (&$hasFailed, $statementStart, $table): void {
        if ($hasFailed || ! str_starts_with($query->sql, $statementStart) || ! str_contains($query->sql, $table)) {
            return;
        }

        $hasFailed = true;

        throw new QueryException(
            $query->connectionName,
            $query->sql,
            $query->bindings,
            new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
        );
    });
}

test('a reuse revocation picked as a deadlock victim is retried', function () {
    failOnceWithDeadlock('update', 'oauth_access_tokens');

    refreshTokens($this, $this->issuedTokens['refresh_token'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    expect(Passport::token()->newQuery()->where('revoked', false)->count())->toBe(0)
        ->and(Passport::refreshToken()->newQuery()->where('revoked', false)->count())->toBe(0);
});

test('a purge of a dead family picked as a deadlock victim is retried', function () {
    redeemCode($this->client, $this->code, $this->pkce['verifier'])->assertStatus(400);
    failOnceWithDeadlock('delete', 'oauth_refresh_tokens');

    $this->artisan('passport:purge', ['--revoked' => true])->assertSuccessful();

    expect(tokenRowCounts())->toBe([0, 0, 0]);
});

test('a purge of expired rotated tokens picked as a deadlock victim is retried', function () {
    ageTokenRows(30 + 8, isRotatedOnly: true);
    failOnceWithDeadlock('delete', 'oauth_refresh_tokens');

    $this->artisan('passport:purge')->assertSuccessful();

    expect(tokenRowCounts())->toBe([1, 1, 1]);
});
