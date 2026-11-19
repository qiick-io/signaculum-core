<?php

declare(strict_types=1);

use App\Ldap\DirectoryUser;
use App\Models\User;
use App\Services\LdapUserSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Testing\ConnectionFake;
use LdapRecord\Testing\LdapFake;

/**
 * The feature's central promise: the connector reads the directory and never writes
 * back. The emulated directory is an ordinary database, so every add, modify, rename
 * and delete the sync could perform would land in these three tables.
 */
beforeEach(function () {
    fakeLdapDirectory();
});

afterEach(function () {
    DirectoryEmulator::tearDown();
});

/**
 * Every row of the emulated directory, as a value that changes when anything is
 * added, renamed, modified or removed.
 *
 * @return array<string, mixed>
 */
function directoryContents(): array
{
    $directory = DB::connection('ldap_default');

    return [
        'objects' => $directory->table('ldap_objects')->orderBy('id')->get()->map(
            fn (object $row): array => (array) $row,
        )->all(),
        'attributes' => $directory->table('ldap_object_attributes')->orderBy('id')->get()->map(
            fn (object $row): array => (array) $row,
        )->all(),
        'values' => $directory->table('ldap_object_attribute_values')->orderBy('id')->get()->map(
            fn (object $row): array => (array) $row,
        )->all(),
    ];
}

/**
 * A directory and a local user table arranged so one sync run has to create, update,
 * reactivate, deactivate and refuse an account: every write path the sync owns.
 *
 * @return array{linked: User, inactive: User, missing: User, localOnly: User}
 */
function arrangeFullSyncRun(): array
{
    enableLdapSettings();

    $guidUpdate = (string) Str::uuid();
    $guidInactive = (string) Str::uuid();

    DirectoryUser::create([
        'cn' => 'Created Person',
        'givenname' => 'Created',
        'sn' => 'Person',
        'mail' => 'created@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    DirectoryUser::create([
        'cn' => 'Updated Person',
        'givenname' => 'Updated',
        'sn' => 'Person',
        'mail' => 'updated@example.com',
        'objectguid' => $guidUpdate,
    ]);

    DirectoryUser::create([
        'cn' => 'Inactive Person',
        'givenname' => 'Inactive',
        'sn' => 'Person',
        'mail' => 'inactive@example.com',
        'objectguid' => $guidInactive,
    ]);

    DirectoryUser::create([
        'cn' => 'Conflicting Person',
        'givenname' => 'Conflicting',
        'sn' => 'Person',
        'mail' => 'local-only@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    return [
        'linked' => User::factory()->create([
            'first_name' => 'Stale',
            'last_name' => 'Name',
            'email' => 'updated@example.com',
            'ldap_guid' => Str::lower($guidUpdate),
            'ldap_dn' => 'cn=Moved Person,dc=local,dc=com',
            'is_active' => true,
        ]),
        'inactive' => User::factory()->create([
            'email' => 'inactive@example.com',
            'ldap_guid' => Str::lower($guidInactive),
            'is_active' => false,
        ]),
        'missing' => User::factory()->create([
            'email' => 'missing@example.com',
            'ldap_guid' => Str::lower((string) Str::uuid()),
            'is_active' => true,
        ]),
        'localOnly' => User::factory()->create([
            'email' => 'local-only@example.com',
            'ldap_guid' => null,
        ]),
    ];
}

test('a full sync run never writes to the directory', function () {
    $users = arrangeFullSyncRun();
    $directoryBefore = directoryContents();

    $result = app(LdapUserSyncService::class)->sync();

    // The run really did everything the sync is allowed to do locally.
    expect($result->created)->toBe(1)
        ->and($result->updated)->toBe(2)
        ->and($result->deactivated)->toBe(1)
        ->and($result->conflicts)->toBe(1)
        ->and($users['linked']->refresh()->first_name)->toBe('Updated')
        ->and($users['inactive']->refresh()->is_active)->toBeTrue()
        ->and($users['missing']->refresh()->is_active)->toBeFalse();

    // And the directory is byte for byte what it was.
    expect(directoryContents())->toBe($directoryBefore);
});

test('a local change is never pushed up to the directory on the next sync', function () {
    $users = arrangeFullSyncRun();
    app(LdapUserSyncService::class)->sync();

    $users['linked']->refresh()->forceFill([
        'first_name' => 'Edited Locally',
        'last_name' => 'By An Admin',
    ])->save();

    $directoryBefore = directoryContents();

    app(LdapUserSyncService::class)->sync();

    expect(directoryContents())->toBe($directoryBefore)
        ->and(DirectoryUser::query()->where('mail', '=', 'updated@example.com')->first()?->getFirstAttribute('givenname'))
        ->toBe('Updated')
        ->and($users['linked']->refresh()->first_name)->toBe('Updated');
});

test('a user deactivated locally is never removed from the directory', function () {
    arrangeFullSyncRun();
    app(LdapUserSyncService::class)->sync();

    $directoryEntryCount = DirectoryUser::query()->get()->count();

    User::query()->where('email', 'created@example.com')->update(['is_active' => false]);
    $directoryBefore = directoryContents();

    app(LdapUserSyncService::class)->sync();

    expect(DirectoryUser::query()->get()->count())->toBe($directoryEntryCount)
        ->and(directoryContents())->toBe($directoryBefore);
});

/**
 * The emulator answers every operation, so it can only show that nothing changed.
 * This connection answers the search and nothing else: any add, modify, delete or
 * rename the sync attempted would fail the test with "LDAP method [x] was unexpected".
 */
test('the sync never sends a write operation to the directory', function () {
    enableLdapSettings();
    DirectoryEmulator::tearDown();

    User::factory()->create([
        'first_name' => 'Stale',
        'email' => 'known@example.com',
        'ldap_guid' => '4b4b4b4b-4b4b-4e4b-4f57-4e454e545259',
        'is_active' => true,
    ]);

    User::factory()->create([
        'email' => 'vanished@example.com',
        'ldap_guid' => Str::lower((string) Str::uuid()),
        'is_active' => true,
    ]);

    $connection = ConnectionFake::make([
        'hosts' => ['127.0.0.1'],
        'base_dn' => 'dc=local,dc=com',
        'use_tls' => true,
    ])->shouldBeConnected();

    $connection->getLdapConnection()->expect(
        LdapFake::operation('search')->andReturn([
            'count' => 2,
            0 => [
                'count' => 4,
                0 => 'objectguid',
                'objectguid' => ['count' => 1, 0 => 'KNOWNENTRY'],
                1 => 'mail',
                'mail' => ['count' => 1, 0 => 'known@example.com'],
                2 => 'givenname',
                'givenname' => ['count' => 1, 0 => 'Known'],
                3 => 'sn',
                'sn' => ['count' => 1, 0 => 'Person'],
                'dn' => 'cn=Known Person,dc=local,dc=com',
            ],
            1 => [
                'count' => 4,
                0 => 'objectguid',
                'objectguid' => ['count' => 1, 0 => 'FRESHENTRY'],
                1 => 'mail',
                'mail' => ['count' => 1, 0 => 'fresh@example.com'],
                2 => 'givenname',
                'givenname' => ['count' => 1, 0 => 'Fresh'],
                3 => 'sn',
                'sn' => ['count' => 1, 0 => 'Person'],
                'dn' => 'cn=Fresh Person,dc=local,dc=com',
            ],
        ]),
    );

    Container::getInstance()->addConnection($connection, 'default');

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->created)->toBe(1)
        ->and($result->updated)->toBe(1)
        ->and($result->deactivated)->toBe(1)
        ->and(User::query()->where('email', 'known@example.com')->value('first_name'))->toBe('Known')
        ->and(User::query()->where('email', 'vanished@example.com')->value('is_active'))->toBeFalse();

    Container::getInstance()->flush();
});
