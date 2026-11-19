<?php

declare(strict_types=1);

use App\Actions\UpdateLdapSettingsAction;
use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Services\LdapSettingsService;
use App\Services\LdapUserSyncService;
use App\Services\SettingsRepositoryService;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    fakeLdapDirectory();
});

afterEach(function () {
    DirectoryEmulator::tearDown();
});

test('a connector without a host or a base DN cannot be saved', function () {
    expect(fn () => enableLdapSettings(['hosts' => []]))
        ->toThrow(InvalidArgumentException::class, 'At least one LDAP host is required.');

    expect(fn () => enableLdapSettings(['baseDn' => '   ']))
        ->toThrow(InvalidArgumentException::class, 'A base DN is required.');

    expect(app(LdapSettingsService::class)->isEnabled())->toBeFalse();
});

/**
 * A settings row written by an older version (or by hand) must not take the sync down:
 * anything that is not an LDAP settings array falls back to the packaged defaults.
 */
test('a settings row that is not an LDAP settings array falls back to the defaults', function () {
    app(SettingsRepositoryService::class)->save([LdapSettingsService::SETTING_KEY => 'not-an-array']);

    $ldapSettings = app(LdapSettingsService::class);

    expect($ldapSettings->raw())->toBe(LdapSettingsService::defaults())
        ->and($ldapSettings->isEnabled())->toBeFalse()
        ->and($ldapSettings->lastSyncedAt())->toBeNull()
        ->and($ldapSettings->forEdit()['has_bind_password'])->toBeFalse()
        ->and(app(LdapUserSyncService::class)->sync()->created)->toBe(0);
});

test('a bind password that cannot be decrypted is read as none at all', function () {
    enableLdapSettings();

    $raw = app(LdapSettingsService::class)->raw();
    $raw['bind_password'] = 'this-is-not-valid-ciphertext';
    app(SettingsRepositoryService::class)->save([LdapSettingsService::SETTING_KEY => $raw]);

    expect(app(LdapSettingsService::class)->decryptedBindPassword())->toBeNull()
        ->and(app(LdapSettingsService::class)->connectionSettings()->bindPassword)->toBeNull();
});

test('a connector saved without a bind password has none to decrypt', function () {
    enableLdapSettings(['bindPassword' => null]);

    expect(app(LdapSettingsService::class)->decryptedBindPassword())->toBeNull()
        ->and(app(LdapSettingsService::class)->forEdit()['has_bind_password'])->toBeFalse();
});

/**
 * The stored interval drives the scheduler, so it is clamped on the way out as well
 * as on the way in: a row written before the clamp existed must not mean a sync
 * every zero minutes, or one a fortnight from now.
 */
test('an out of range sync interval in the settings row is clamped on read', function () {
    enableLdapSettings();

    $storeInterval = function (int $minutes): void {
        $raw = app(LdapSettingsService::class)->raw();
        $raw['sync_interval_minutes'] = $minutes;
        app(SettingsRepositoryService::class)->save([LdapSettingsService::SETTING_KEY => $raw]);
    };

    $storeInterval(0);
    expect(app(LdapSettingsService::class)->syncIntervalMinutes())->toBe(1)
        ->and(app(LdapSettingsService::class)->connectionSettings()->syncIntervalMinutes)->toBe(1);

    $storeInterval(999999);
    expect(app(LdapSettingsService::class)->syncIntervalMinutes())->toBe(10080);
});

test('a saved settings change records the admin who made it', function () {
    $admin = instanceAdmin();

    enableLdapSettings();
    $anonymousActivity = Activity::query()
        ->where('event', ActivityEventEnum::SettingsUpdated->value)
        ->latest('id')
        ->first();

    app(UpdateLdapSettingsAction::class)->execute(
        app(LdapSettingsService::class)->connectionSettings(),
        $admin,
    );

    $attributedActivity = Activity::query()
        ->where('event', ActivityEventEnum::SettingsUpdated->value)
        ->latest('id')
        ->first();

    expect($anonymousActivity?->causer_id)->toBeNull()
        ->and($attributedActivity?->causer_id)->toBe((string) $admin->getKey())
        ->and($attributedActivity?->causer_type)->toBe(User::class);
});
