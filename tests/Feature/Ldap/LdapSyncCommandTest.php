<?php

declare(strict_types=1);

use App\Jobs\SyncLdapUsersJob;
use App\Ldap\DirectoryUser;
use App\Models\User;
use App\Services\LdapSettingsService;
use App\Services\LdapUserSyncService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\LdapRecordException;
use LdapRecord\Testing\ConnectionFake;
use LdapRecord\Testing\LdapFake;

beforeEach(function () {
    fakeLdapDirectory();
});

afterEach(function () {
    DirectoryEmulator::tearDown();
});

/**
 * Replace the emulated directory with a connection whose search fails, the way a
 * directory that goes away mid-run does.
 */
function breakTheDirectory(string $error = 'Invalid DN syntax'): void
{
    DirectoryEmulator::tearDown();

    $connection = ConnectionFake::make([
        'hosts' => ['127.0.0.1'],
        'base_dn' => 'dc=local,dc=com',
        'use_tls' => true,
    ])->shouldBeConnected();

    $connection->getLdapConnection()->expect([
        LdapFake::operation('search')->andThrow(new LdapRecordException($error)),
        LdapFake::operation('listing')->andThrow(new LdapRecordException($error)),
        LdapFake::operation('read')->andThrow(new LdapRecordException($error)),
    ]);

    Container::getInstance()->addConnection($connection, 'default');
}

test('the command reports the counters of the run it performed', function () {
    enableLdapSettings();

    DirectoryUser::create([
        'cn' => 'New Person',
        'givenname' => 'New',
        'sn' => 'Person',
        'mail' => 'new@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    DirectoryUser::create([
        'cn' => 'Taken Person',
        'givenname' => 'Taken',
        'sn' => 'Person',
        'mail' => 'taken@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    User::factory()->create(['email' => 'taken@example.com', 'ldap_guid' => null]);

    $this->artisan('signaculum:ldap-sync')
        ->expectsOutputToContain('created 1, updated 0, deactivated 0, conflicts 1, skipped 0')
        ->assertSuccessful();

    expect(User::query()->where('email', 'new@example.com')->exists())->toBeTrue();
});

test('the command exits non-zero and says why when the directory call fails', function () {
    enableLdapSettings();
    breakTheDirectory('Invalid DN syntax');

    $this->artisan('signaculum:ldap-sync')
        ->expectsOutputToContain('LDAP sync failed')
        ->assertFailed();

    expect(app(LdapSettingsService::class)->lastSyncedAt())->toBeNull();
});

test('the command says the connector is off instead of claiming a successful run', function () {
    enableLdapSettings(['isEnabled' => false]);

    DirectoryUser::create([
        'cn' => 'Ignored Person',
        'givenname' => 'Ignored',
        'sn' => 'Person',
        'mail' => 'ignored@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    $this->artisan('signaculum:ldap-sync')
        ->expectsOutputToContain('disabled')
        ->doesntExpectOutputToContain('LDAP sync finished')
        ->assertSuccessful();

    expect(User::query()->where('email', 'ignored@example.com')->exists())->toBeFalse()
        ->and(app(LdapSettingsService::class)->lastSyncedAt())->toBeNull();
});

test('the scheduled job does nothing while the connector is off', function () {
    enableLdapSettings(['isEnabled' => false]);

    DirectoryUser::create([
        'cn' => 'Disabled Person',
        'givenname' => 'Disabled',
        'sn' => 'Person',
        'mail' => 'disabled@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    (new SyncLdapUsersJob)->handle(app(LdapSettingsService::class), app(LdapUserSyncService::class));

    expect(User::query()->where('email', 'disabled@example.com')->exists())->toBeFalse()
        ->and(app(LdapSettingsService::class)->lastSyncedAt())->toBeNull();
});

test('the scheduled job runs when nothing has been synced yet', function () {
    enableLdapSettings();

    expect(app(LdapSettingsService::class)->lastSyncedAt())->toBeNull();

    DirectoryUser::create([
        'cn' => 'First Person',
        'givenname' => 'First',
        'sn' => 'Person',
        'mail' => 'first@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    (new SyncLdapUsersJob)->handle(app(LdapSettingsService::class), app(LdapUserSyncService::class));

    expect(User::query()->where('email', 'first@example.com')->exists())->toBeTrue()
        ->and(app(LdapSettingsService::class)->lastSyncedAt())->not->toBeNull();
});

/**
 * A stored timestamp the scheduler cannot read must not park the sync forever:
 * an unparseable `last_synced_at` is treated as "never synced".
 */
test('the scheduled job runs when the stored last sync timestamp is unreadable', function () {
    enableLdapSettings();
    app(LdapSettingsService::class)->markSyncedAt('not-a-timestamp');

    DirectoryUser::create([
        'cn' => 'Recovered Person',
        'givenname' => 'Recovered',
        'sn' => 'Person',
        'mail' => 'recovered@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    (new SyncLdapUsersJob)->handle(app(LdapSettingsService::class), app(LdapUserSyncService::class));

    expect(User::query()->where('email', 'recovered@example.com')->exists())->toBeTrue();
});

test('a failed sync job is logged without the directory credentials', function () {
    $logger = Log::spy();

    (new SyncLdapUsersJob)->failed(new RuntimeException('bind to cn=admin,dc=local,dc=com failed'));

    $logger->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'LDAP user sync job failed'
            && $context['message'] === 'bind to cn=admin,dc=local,dc=com failed');
});

test('a failed sync job logs even when the queue reports no exception', function () {
    $logger = Log::spy();

    (new SyncLdapUsersJob)->failed(null);

    $logger->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'LDAP user sync job failed'
            && $context['message'] === null);
});

test('a directory failure inside the job leaves the last sync timestamp untouched', function () {
    enableLdapSettings();
    app(LdapSettingsService::class)->markSyncedAt(now()->subDay()->toIso8601String());
    $before = app(LdapSettingsService::class)->lastSyncedAt();

    breakTheDirectory();

    expect(fn () => (new SyncLdapUsersJob)->handle(app(LdapSettingsService::class), app(LdapUserSyncService::class)))
        ->toThrow(LdapRecordException::class);

    expect(app(LdapSettingsService::class)->lastSyncedAt())->toBe($before);
});
