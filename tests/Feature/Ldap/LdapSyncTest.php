<?php

declare(strict_types=1);

use App\Actions\TestLdapConnectionAction;
use App\Enums\ActivityEventEnum;
use App\Enums\LdapEncryptionEnum;
use App\Enums\RoleEnum;
use App\Jobs\SyncLdapUsersJob;
use App\Ldap\DirectoryUser;
use App\Models\User;
use App\Services\LdapSettingsService;
use App\Services\LdapUserSyncService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\Testing\ConnectionFake;
use LdapRecord\Testing\LdapFake;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeLdapDirectory();
});

afterEach(function () {
    DirectoryEmulator::tearDown();
});

test('TLS encryption is required by default', function () {
    $defaults = LdapSettingsService::defaults();

    expect($defaults['encryption'])->toBe(LdapEncryptionEnum::Tls->value)
        ->and($defaults['port'])->toBe(636)
        ->and(config('ldap.connections.default.use_tls'))->toBeTrue();
});

test('enabling LDAP without TLS or StartTLS is rejected', function () {
    expect(fn () => enableLdapSettings([
        'encryption' => LdapEncryptionEnum::None,
        'port' => 389,
    ]))->toThrow(InvalidArgumentException::class);
});

test('sync creates updates and deactivates users from the directory', function () {
    enableLdapSettings();

    $guidKeep = (string) Str::uuid();
    $guidUpdate = (string) Str::uuid();
    $guidGone = (string) Str::uuid();

    DirectoryUser::create([
        'cn' => 'Ada Lovelace',
        'givenname' => 'Ada',
        'sn' => 'Lovelace',
        'mail' => 'ada@example.com',
        'uid' => 'ada',
        'objectguid' => $guidKeep,
    ]);

    DirectoryUser::create([
        'cn' => 'Grace Hopper',
        'givenname' => 'Grace',
        'sn' => 'Hopper',
        'mail' => 'grace@example.com',
        'uid' => 'grace',
        'objectguid' => $guidUpdate,
    ]);

    User::factory()->create([
        'first_name' => 'Old',
        'last_name' => 'Name',
        'email' => 'grace@example.com',
        'ldap_guid' => Str::lower($guidUpdate),
        'ldap_dn' => 'cn=Grace Hopper,dc=local,dc=com',
        'is_active' => true,
    ]);

    User::factory()->create([
        'first_name' => 'Missing',
        'last_name' => 'Person',
        'email' => 'gone@example.com',
        'ldap_guid' => Str::lower($guidGone),
        'is_active' => true,
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->created)->toBe(1)
        ->and($result->updated)->toBe(1)
        ->and($result->deactivated)->toBe(1);

    $ada = User::query()->where('email', 'ada@example.com')->first();
    $grace = User::query()->where('email', 'grace@example.com')->first();
    $gone = User::query()->where('email', 'gone@example.com')->first();

    expect($ada)->not->toBeNull()
        ->and($ada?->ldap_guid)->toBe(Str::lower($guidKeep))
        ->and($ada?->first_name)->toBe('Ada')
        ->and($ada?->is_active)->toBeTrue()
        ->and($grace?->first_name)->toBe('Grace')
        ->and($grace?->last_name)->toBe('Hopper')
        ->and($gone?->is_active)->toBeFalse();

    expect(Activity::query()->where('event', ActivityEventEnum::LdapSyncCompleted->value)->count())->toBe(1);
});

test('sync logs a conflict when a local-only account already owns the email', function () {
    enableLdapSettings();

    User::factory()->create([
        'email' => 'taken@example.com',
        'ldap_guid' => null,
    ]);

    DirectoryUser::create([
        'cn' => 'Directory User',
        'givenname' => 'Directory',
        'sn' => 'User',
        'mail' => 'taken@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->conflicts)->toBe(1)
        ->and($result->created)->toBe(0)
        ->and(User::query()->whereNotNull('ldap_guid')->count())->toBe(0)
        ->and(Activity::query()->where('event', ActivityEventEnum::LdapSyncConflict->value)->count())->toBe(1);
});

test('sync respects a simple user filter', function () {
    enableLdapSettings([
        'userFilter' => '(department=Engineering)',
    ]);

    DirectoryUser::create([
        'cn' => 'Engineer',
        'givenname' => 'Eng',
        'sn' => 'One',
        'mail' => 'eng@example.com',
        'department' => 'Engineering',
        'objectguid' => (string) Str::uuid(),
    ]);

    DirectoryUser::create([
        'cn' => 'Salesperson',
        'givenname' => 'Sale',
        'sn' => 'Two',
        'mail' => 'sales@example.com',
        'department' => 'Sales',
        'objectguid' => (string) Str::uuid(),
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->created)->toBe(1)
        ->and(User::query()->where('email', 'eng@example.com')->exists())->toBeTrue()
        ->and(User::query()->where('email', 'sales@example.com')->exists())->toBeFalse();
});

test('bind password is encrypted and never returned or logged', function () {
    $logger = Log::spy();

    enableLdapSettings([
        'bindPassword' => 'super-secret-bind-password',
    ]);

    $raw = app(LdapSettingsService::class)->raw();
    $forEdit = app(LdapSettingsService::class)->forEdit();

    expect($raw['bind_password'])->not->toBe('super-secret-bind-password')
        ->and(Crypt::decryptString((string) $raw['bind_password']))->toBe('super-secret-bind-password')
        ->and($forEdit)->not->toHaveKey('bind_password')
        ->and($forEdit['has_bind_password'])->toBeTrue();

    $settingsActivity = Activity::query()
        ->where('event', ActivityEventEnum::SettingsUpdated->value)
        ->latest('id')
        ->first();

    expect($settingsActivity)->not->toBeNull()
        ->and(json_encode($settingsActivity?->properties))->not->toContain('super-secret-bind-password')
        ->and(json_encode($settingsActivity?->properties))->not->toContain((string) $raw['bind_password']);

    $logger->shouldNotHaveReceived('info', fn (...$arguments): bool => str_contains(json_encode($arguments) ?: '', 'super-secret-bind-password'));
    $logger->shouldNotHaveReceived('debug', fn (...$arguments): bool => str_contains(json_encode($arguments) ?: '', 'super-secret-bind-password'));
    $logger->shouldNotHaveReceived('warning', fn (...$arguments): bool => str_contains(json_encode($arguments) ?: '', 'super-secret-bind-password'));
});

test('empty bind password on save keeps the existing ciphertext', function () {
    enableLdapSettings([
        'bindPassword' => 'first-secret',
    ]);

    $firstCipher = app(LdapSettingsService::class)->raw()['bind_password'];

    enableLdapSettings([
        'bindPassword' => null,
    ]);

    expect(app(LdapSettingsService::class)->raw()['bind_password'])->toBe($firstCipher)
        ->and(app(LdapSettingsService::class)->decryptedBindPassword())->toBe('first-secret');
});

test('signaculum:ldap-sync command runs the sync', function () {
    enableLdapSettings();

    DirectoryUser::create([
        'cn' => 'Command User',
        'givenname' => 'Command',
        'sn' => 'User',
        'mail' => 'command@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    $this->artisan('signaculum:ldap-sync')
        ->assertSuccessful();

    expect(User::query()->where('email', 'command@example.com')->exists())->toBeTrue();
});

test('SyncLdapUsersJob respects the sync interval', function () {
    enableLdapSettings([
        'syncIntervalMinutes' => 60,
    ]);

    app(LdapSettingsService::class)->markSyncedAt(now()->toIso8601String());

    DirectoryUser::create([
        'cn' => 'Interval User',
        'givenname' => 'Interval',
        'sn' => 'User',
        'mail' => 'interval@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    (new SyncLdapUsersJob)->handle(
        app(LdapSettingsService::class),
        app(LdapUserSyncService::class),
    );

    expect(User::query()->where('email', 'interval@example.com')->exists())->toBeFalse();

    app(LdapSettingsService::class)->markSyncedAt(now()->subHours(2)->toIso8601String());

    (new SyncLdapUsersJob)->handle(
        app(LdapSettingsService::class),
        app(LdapUserSyncService::class),
    );

    expect(User::query()->where('email', 'interval@example.com')->exists())->toBeTrue();
});

test('TestLdapConnectionAction reports success against the emulator', function () {
    enableLdapSettings();

    $result = app(TestLdapConnectionAction::class)->execute();

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->message)->toContain('successful')
        ->and($result->message)->not->toContain('bind-secret-value');
});

test('the sync does nothing at all while the connector is off', function () {
    enableLdapSettings(['isEnabled' => false]);

    DirectoryUser::create([
        'cn' => 'Off Person',
        'givenname' => 'Off',
        'sn' => 'Person',
        'mail' => 'off@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->created)->toBe(0)
        ->and($result->updated)->toBe(0)
        ->and($result->deactivated)->toBe(0)
        ->and(User::query()->where('email', 'off@example.com')->exists())->toBeFalse()
        ->and(app(LdapSettingsService::class)->lastSyncedAt())->toBeNull()
        ->and(Activity::query()->where('event', ActivityEventEnum::LdapSyncCompleted->value)->exists())->toBeFalse();
});

/**
 * An entry the directory cannot identify is skipped, never created under a guessed
 * identity: a user row without a stable `ldap_guid` could never be matched again.
 */
test('a directory entry without a resolvable guid is skipped', function () {
    enableLdapSettings();

    DirectoryUser::create([
        'cn' => 'Anonymous Person',
        'givenname' => 'Anonymous',
        'sn' => 'Person',
        'mail' => 'anonymous@example.com',
        'entryuuid' => '',
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->skipped)->toBe(1)
        ->and($result->created)->toBe(0)
        ->and(User::query()->where('email', 'anonymous@example.com')->exists())->toBeFalse();
});

test('a guid is taken from entryUUID when the directory has no objectGUID', function () {
    enableLdapSettings();

    DirectoryUser::create([
        'cn' => 'OpenLDAP Person',
        'givenname' => 'Open',
        'sn' => 'Person',
        'mail' => 'openldap@example.com',
        'entryuuid' => 'C0FFEE00-1234-4000-8000-ABCDEF123456',
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->created)->toBe(1)
        ->and(User::query()->where('email', 'openldap@example.com')->value('ldap_guid'))
        ->toBe('c0ffee00-1234-4000-8000-abcdef123456');
});

test('a directory entry without a usable email address is skipped', function () {
    enableLdapSettings();

    DirectoryUser::create([
        'cn' => 'No Mail',
        'givenname' => 'No',
        'sn' => 'Mail',
        'objectguid' => (string) Str::uuid(),
    ]);

    DirectoryUser::create([
        'cn' => 'Bad Mail',
        'givenname' => 'Bad',
        'sn' => 'Mail',
        'mail' => 'not-an-email-address',
        'objectguid' => (string) Str::uuid(),
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->skipped)->toBe(2)
        ->and($result->created)->toBe(0)
        ->and(User::query()->count())->toBe(0);
});

test('a directory entry with no name attributes still gets a usable account', function () {
    enableLdapSettings();

    DirectoryUser::create([
        'cn' => 'Mail Only',
        'mail' => 'mailonly@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    $result = app(LdapUserSyncService::class)->sync();
    $user = User::query()->where('email', 'mailonly@example.com')->first();

    expect($result->created)->toBe(1)
        ->and($user?->first_name)->toBe('User')
        ->and($user?->last_name)->toBeNull();
});

test('an attribute left blank in the settings is never read from the directory', function () {
    enableLdapSettings(['attributeFamilyName' => '']);

    DirectoryUser::create([
        'cn' => 'Blank Attribute',
        'givenname' => 'Blank',
        'sn' => 'Attribute',
        'mail' => 'blank-attribute@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    app(LdapUserSyncService::class)->sync();
    $user = User::query()->where('email', 'blank-attribute@example.com')->first();

    expect($user?->first_name)->toBe('Blank')
        ->and($user?->last_name)->toBeNull();
});

test('a linked user is never moved onto an email another account already owns', function () {
    enableLdapSettings();

    $guid = (string) Str::uuid();

    $linkedUser = User::factory()->create([
        'first_name' => 'Linked',
        'last_name' => 'Person',
        'email' => 'linked@example.com',
        'ldap_guid' => Str::lower($guid),
        'is_active' => true,
    ]);

    $localOwner = User::factory()->create(['email' => 'owned@example.com', 'ldap_guid' => null]);

    DirectoryUser::create([
        'cn' => 'Linked Person',
        'givenname' => 'Renamed',
        'sn' => 'Person',
        'mail' => 'owned@example.com',
        'objectguid' => $guid,
    ]);

    $result = app(LdapUserSyncService::class)->sync();
    $conflict = Activity::query()->where('event', ActivityEventEnum::LdapSyncConflict->value)->first();

    expect($result->conflicts)->toBe(1)
        ->and($result->updated)->toBe(0)
        ->and($linkedUser->refresh()->email)->toBe('linked@example.com')
        ->and($linkedUser->first_name)->toBe('Linked')
        ->and($localOwner->refresh()->ldap_guid)->toBeNull()
        ->and($conflict?->properties['email'])->toBe('owned@example.com')
        ->and($conflict?->properties['local_user_subject'])->toBe($localOwner->subject);
});

test('a directory entry whose email belongs to another linked account is skipped', function () {
    enableLdapSettings();

    User::factory()->create([
        'email' => 'shared@example.com',
        'ldap_guid' => Str::lower((string) Str::uuid()),
        'is_active' => false,
    ]);

    DirectoryUser::create([
        'cn' => 'Second Entry',
        'givenname' => 'Second',
        'sn' => 'Entry',
        'mail' => 'shared@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->skipped)->toBe(1)
        ->and($result->created)->toBe(0)
        ->and($result->conflicts)->toBe(0)
        ->and(User::query()->where('email', 'shared@example.com')->count())->toBe(1)
        ->and(Activity::query()->where('event', ActivityEventEnum::LdapSyncConflict->value)->exists())->toBeFalse();
});

test('a user who returns to the directory is reactivated', function () {
    enableLdapSettings();

    $guid = (string) Str::uuid();

    $user = User::factory()->create([
        'first_name' => 'Returning',
        'last_name' => 'Person',
        'email' => 'returning@example.com',
        'ldap_guid' => Str::lower($guid),
        'is_active' => false,
    ]);

    DirectoryUser::create([
        'cn' => 'Returning Person',
        'givenname' => 'Returning',
        'sn' => 'Person',
        'mail' => 'returning@example.com',
        'objectguid' => $guid,
    ]);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->updated)->toBe(1)
        ->and($result->deactivated)->toBe(0)
        ->and($user->refresh()->is_active)->toBeTrue();
});

/**
 * The directory must not be able to lock everyone out of the instance: the last
 * instance admin stays active and the refusal is logged instead of counted.
 */
test('the last instance admin is kept active when they leave the directory', function () {
    enableLdapSettings();
    $logger = Log::spy();

    syncPermissions();
    $admin = User::factory()->create([
        'email' => 'admin@example.com',
        'ldap_guid' => Str::lower((string) Str::uuid()),
        'is_active' => true,
    ]);
    $admin->assignRole(RoleEnum::SuperAdmin->value);

    $result = app(LdapUserSyncService::class)->sync();

    expect($result->deactivated)->toBe(0)
        ->and($admin->refresh()->is_active)->toBeTrue();

    $logger->shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'LDAP sync could not deactivate user'
            && $context['user_subject'] === $admin->subject);
});

/**
 * Record the LDAP filter the sync sends, with a connection that returns no entries.
 */
function filterSentByTheSync(): string
{
    DirectoryEmulator::tearDown();

    $sentFilter = '';
    $connection = ConnectionFake::make([
        'hosts' => ['127.0.0.1'],
        'base_dn' => 'dc=local,dc=com',
        'use_tls' => true,
    ])->shouldBeConnected();

    $connection->getLdapConnection()->expect(
        LdapFake::operation('search')->with([
            fn (string $distinguishedName): bool => true,
            function (string $filter) use (&$sentFilter): bool {
                $sentFilter = $filter;

                return true;
            },
        ])->andReturn([]),
    );

    Container::getInstance()->addConnection($connection, 'default');

    app(LdapUserSyncService::class)->sync();

    return $sentFilter;
}

/**
 * A filter the settings cannot express as one equality must reach the directory
 * verbatim: escaping it as a single value would silently match nothing.
 */
test('a compound user filter reaches the directory unchanged', function () {
    enableLdapSettings(['userFilter' => '(&(objectClass=person)(department=Engineering))']);

    expect(filterSentByTheSync())->toContain('(&(objectClass=person)(department=Engineering))');
});

test('a simple equality filter is escaped before it reaches the directory', function () {
    enableLdapSettings(['userFilter' => '(department=Engineering)']);

    $sentFilter = filterSentByTheSync();

    expect($sentFilter)->toContain('department=')
        ->and($sentFilter)->not->toContain('department=Engineering');
});

/**
 * Active Directory installs commonly map the address to `userPrincipalName` and have
 * no `givenName`: the configured attribute map is what the sync reads, not the defaults.
 */
test('a custom attribute map is what the sync reads from the directory', function () {
    enableLdapSettings([
        'attributeMail' => 'userprincipalname',
        'attributeGivenName' => 'notpresent',
        'attributeDisplayName' => 'displayname',
    ]);

    DirectoryUser::create([
        'cn' => 'Mapped Person',
        'mail' => 'wrong@example.com',
        'userprincipalname' => 'mapped@example.com',
        'displayname' => 'Mapped Person',
        'sn' => 'Person',
        'objectguid' => (string) Str::uuid(),
    ]);

    $result = app(LdapUserSyncService::class)->sync();
    $user = User::query()->where('email', 'mapped@example.com')->first();

    expect($result->created)->toBe(1)
        ->and($user?->first_name)->toBe('Mapped Person')
        ->and($user?->last_name)->toBe('Person')
        ->and(User::query()->where('email', 'wrong@example.com')->exists())->toBeFalse();
});
