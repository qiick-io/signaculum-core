<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Real two-session races on the token family of an authorization code (RFC 6749 §4.1.2,
 * RFC 9700 §4.5 and §4.14.2): a second PHP process with its own database connection
 * runs one request while this one is inside the critical section of another.
 *
 * Runs on PostgreSQL and MySQL only: SQLite has a single writer, so it cannot race.
 */
beforeEach(function () {
    if (! in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true) || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Needs PostgreSQL or MySQL row locks and pcntl.');
    }

    $this->user = User::factory()->create();
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
    $this->actingAs($this->user);
    $this->code = approveAuthorization($this->client, $this->pkce['challenge'])['code'];
    $this->issuedTokens = redeemCode($this->client, $this->code, $this->pkce['verifier'])->assertOk()->json();
    $this->authCodeId = Passport::authCode()->newQuery()->sole()->getKey();
});

// DatabaseTruncation empties the tables before each test only: leave no committed rows to the suites after this one.
afterEach(function () {
    if (in_array(DB::getDriverName(), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->truncateDatabaseTables();
    }
});

/**
 * Refresh the issued refresh token and, as soon as that refresh runs a query starting
 * with `$pauseAtQuery` on `$pauseAtTable`, run `$parallelRequest` in a forked process
 * with its own database session. This process pauses long enough for the other one to
 * reach its first lock, carries on, then waits for it to end.
 */
function raceWhileRefreshing(object $testCase, string $pauseAtQuery, string $pauseAtTable, Closure $parallelRequest): void
{
    $hasForked = false;
    $forkedProcessId = null;

    DB::listen(function (QueryExecuted $query) use (&$hasForked, &$forkedProcessId, $pauseAtQuery, $pauseAtTable, $parallelRequest): void {
        if ($hasForked || ! str_starts_with($query->sql, $pauseAtQuery) || ! str_contains($query->sql, $pauseAtTable)) {
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
                $parallelRequest();
            } finally {
                // Skip shutdown and destructors, which would touch the parent's socket.
                posix_kill(posix_getpid(), SIGKILL);
            }
        }

        sleep(3);
    });

    refreshTokens($testCase, $testCase->issuedTokens['refresh_token']);

    expect($forkedProcessId)->toBeInt()->toBeGreaterThan(0);

    pcntl_waitpid($forkedProcessId, $forkedProcessStatus);
}

// The refresh is past its locks and inserting the new access token when the replay arrives.
test('a code replayed while its family refreshes also revokes the refreshed tokens', function () {
    raceWhileRefreshing($this, 'insert into', 'oauth_access_tokens', function (): void {
        redeemCode($this->client, $this->code, $this->pkce['verifier']);
    });

    $familyAccessTokens = Passport::token()->newQuery()->where('auth_code_id', $this->authCodeId)->get();

    expect($familyAccessTokens)->toHaveCount(2)
        ->and($familyAccessTokens->every(fn ($accessToken): bool => (bool) $accessToken->revoked))->toBeTrue()
        ->and(Passport::refreshToken()->newQuery()->where('revoked', false)->count())->toBe(0);
});

// Both refreshes have read the refresh token as valid before either one revokes it. The
// loser presents a refresh token the winner just rotated, which is a reuse: the family
// is revoked, including the winner's new tokens (RFC 9700 §4.14.2).
test('two parallel refreshes of one refresh token yield one new token, then revoke the family', function () {
    raceWhileRefreshing($this, 'select', 'oauth_refresh_tokens', function (): void {
        refreshTokens($this, $this->issuedTokens['refresh_token']);
    });

    expect(Passport::token()->newQuery()->where('auth_code_id', $this->authCodeId)->count())->toBe(2)
        ->and(Passport::refreshToken()->newQuery()->count())->toBe(2)
        ->and(Passport::refreshToken()->newQuery()->where('revoked', false)->count())->toBe(0);
});

// The refresh of the rotated token is past its locks and inserting the new access token
// when the old, already rotated refresh token is presented again.
test('a rotated refresh token reused while its family refreshes also revokes the refreshed tokens', function () {
    $rotatedRefreshToken = $this->issuedTokens['refresh_token'];
    $this->issuedTokens = refreshTokens($this, $rotatedRefreshToken)->assertOk()->json();

    raceWhileRefreshing($this, 'insert into', 'oauth_access_tokens', function () use ($rotatedRefreshToken): void {
        refreshTokens($this, $rotatedRefreshToken);
    });

    $familyAccessTokens = Passport::token()->newQuery()->where('auth_code_id', $this->authCodeId)->get();

    expect($familyAccessTokens)->toHaveCount(3)
        ->and($familyAccessTokens->every(fn ($accessToken): bool => (bool) $accessToken->revoked))->toBeTrue()
        ->and(Passport::refreshToken()->newQuery()->where('revoked', false)->count())->toBe(0);
});

/**
 * Point the race at a device code family (RFC 9700 §2.2.2): its rotated refresh token is
 * the one raceWhileRefreshing() refreshes.
 *
 * @return array{device_code: string, issued: array<string, mixed>, refreshed: array<string, mixed>}
 */
function raceOnDeviceFamily(object $testCase): array
{
    $deviceTokens = deviceTokenFamily($testCase);
    $testCase->issuedTokens = $deviceTokens['refreshed'];

    return $deviceTokens;
}

/**
 * Every access token of the family of `$deviceCode`, and whether any of their refresh tokens is still live.
 *
 * @return array{accessTokens: Collection<int, Model>, liveRefreshTokenCount: int}
 */
function deviceFamilyState(string $deviceCode): array
{
    $familyAccessTokens = Passport::token()->newQuery()->where('device_code_id', $deviceCode)->get();

    return [
        'accessTokens' => $familyAccessTokens,
        'liveRefreshTokenCount' => Passport::refreshToken()->newQuery()
            ->whereIn('access_token_id', $familyAccessTokens->modelKeys())
            ->where('revoked', false)
            ->count(),
    ];
}

test('a device code replayed while its family refreshes also revokes the refreshed tokens', function () {
    $deviceTokens = raceOnDeviceFamily($this);

    raceWhileRefreshing($this, 'insert into', 'oauth_access_tokens', function () use ($deviceTokens): void {
        redeemDeviceCode($this->client, $deviceTokens['device_code']);
    });

    $familyState = deviceFamilyState($deviceTokens['device_code']);

    expect($familyState['accessTokens'])->toHaveCount(3)
        ->and($familyState['accessTokens']->every(fn ($accessToken): bool => (bool) $accessToken->revoked))->toBeTrue()
        ->and($familyState['liveRefreshTokenCount'])->toBe(0);
});

test('a rotated device refresh token reused while its family refreshes also revokes the refreshed tokens', function () {
    $deviceTokens = raceOnDeviceFamily($this);

    raceWhileRefreshing($this, 'insert into', 'oauth_access_tokens', function () use ($deviceTokens): void {
        refreshTokens($this, $deviceTokens['issued']['refresh_token']);
    });

    $familyState = deviceFamilyState($deviceTokens['device_code']);

    expect($familyState['accessTokens'])->toHaveCount(3)
        ->and($familyState['accessTokens']->every(fn ($accessToken): bool => (bool) $accessToken->revoked))->toBeTrue()
        ->and($familyState['liveRefreshTokenCount'])->toBe(0);
});
