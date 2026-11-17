<?php

declare(strict_types=1);

use App\Actions\TestLdapConnectionAction;
use App\Actions\UpdateLdapSettingsAction;
use App\Data\LdapSettingsData;
use App\Enums\ActivityEventEnum;
use App\Enums\LdapEncryptionEnum;
use App\Jobs\SyncLdapUsersJob;
use App\Ldap\DirectoryUser;
use App\Models\User;
use App\Services\LdapSettingsService;
use App\Services\LdapUserSyncService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config([
        'ldap.default' => 'default',
        'ldap.connections.default' => [
            'hosts' => ['127.0.0.1'],
            'username' => 'cn=admin,dc=local,dc=com',
            'password' => 'secret',
            'port' => 636,
            'base_dn' => 'dc=local,dc=com',
            'timeout' => 5,
            'use_tls' => true,
            'use_starttls' => false,
            'use_sasl' => false,
        ],
        'ldap.logging.enabled' => false,
    ]);

    DirectoryEmulator::setup('default');
});

afterEach(function () {
    DirectoryEmulator::tearDown();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function enableLdapSettings(array $overrides = []): void
{
    $defaults = [
        'isEnabled' => true,
        'hosts' => ['127.0.0.1'],
        'port' => 636,
        'encryption' => LdapEncryptionEnum::Tls,
        'bindDn' => 'cn=admin,dc=local,dc=com',
        'bindPassword' => 'bind-secret-value',
        'baseDn' => 'dc=local,dc=com',
        'userFilter' => '(objectClass=person)',
        'attributeMail' => 'mail',
        'attributeGivenName' => 'givenName',
        'attributeFamilyName' => 'sn',
        'attributeDisplayName' => 'displayName',
        'attributeUsername' => 'uid',
        'attributeGuid' => 'objectGUID',
        'syncIntervalMinutes' => 60,
    ];

    $merged = array_merge($defaults, $overrides);

    app(UpdateLdapSettingsAction::class)->execute(new LdapSettingsData(...$merged));
}

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
