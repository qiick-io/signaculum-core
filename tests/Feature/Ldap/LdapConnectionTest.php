<?php

declare(strict_types=1);

use App\Actions\TestLdapConnectionAction;
use App\Data\LdapSettingsData;
use App\Enums\LdapEncryptionEnum;
use App\Services\LdapConnectionService;
use Illuminate\Support\Facades\Log;
use LdapRecord\Auth\BindException;
use LdapRecord\Connection;
use LdapRecord\ConnectionException;
use LdapRecord\Container;
use LdapRecord\LdapRecordException;
use LdapRecord\Testing\ConnectionFake;
use LdapRecord\Testing\LdapFake;

/**
 * The bind password used by `enableLdapSettings()`; no probe result may contain it.
 */
const LDAP_BIND_SECRET = 'bind-secret-value';

afterEach(function () {
    Container::getInstance()->flush();
});

/**
 * Register a fake default connection whose bind/startTLS behaviour the test chooses.
 *
 * `shouldNotBeConnected()` keeps LdapRecord's real `Auth\Guard` in place, so the
 * exception mapping under test is the one a live directory would produce.
 */
function fakeLdapConnection(array $config = []): ConnectionFake
{
    $connection = ConnectionFake::make(array_merge([
        'hosts' => ['127.0.0.1'],
        'username' => 'cn=admin,dc=local,dc=com',
        'password' => LDAP_BIND_SECRET,
        'port' => 636,
        'base_dn' => 'dc=local,dc=com',
        'use_tls' => true,
        'use_starttls' => false,
        'use_sasl' => false,
    ], $config))->shouldNotBeConnected();

    Container::getInstance()->addConnection($connection, 'default');

    return $connection;
}

/**
 * Settings for a probe of a connector that was never saved (the #78 "test before save" flow).
 */
function ldapProbeSettings(array $overrides = []): LdapSettingsData
{
    return new LdapSettingsData(...array_merge([
        'isEnabled' => true,
        'hosts' => ['127.0.0.1'],
        'port' => 636,
        'encryption' => LdapEncryptionEnum::Tls,
        'bindDn' => 'cn=admin,dc=local,dc=com',
        'bindPassword' => LDAP_BIND_SECRET,
        'baseDn' => 'dc=local,dc=com',
        'userFilter' => '(objectClass=person)',
        'attributeMail' => 'mail',
        'attributeGivenName' => 'givenName',
        'attributeFamilyName' => 'sn',
        'attributeDisplayName' => 'displayName',
        'attributeUsername' => 'uid',
        'attributeGuid' => 'objectGUID',
        'syncIntervalMinutes' => 60,
    ], $overrides));
}

test('a reachable directory is reported as a successful connection', function () {
    fakeLdapConnection()->getLdapConnection()->expect(
        LdapFake::operation('bind')->andReturnResponse(),
    );

    $result = app(TestLdapConnectionAction::class)->execute(ldapProbeSettings());

    expect($result->isSuccessful)->toBeTrue()
        ->and($result->message)->toBe('Connection successful.');
});

test('a refused bind is reported as a credentials failure and never echoes the password', function () {
    fakeLdapConnection()->getLdapConnection()->expect(
        LdapFake::operation('bind')->andThrow(new BindException('Invalid credentials')),
    );

    $result = app(TestLdapConnectionAction::class)->execute(ldapProbeSettings());

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->message)->toBe('Bind failed with the configured credentials.')
        ->and($result->message)->not->toContain(LDAP_BIND_SECRET);
});

/**
 * A host that is down, and a bind that times out, both surface as `Auth\BindException`:
 * LdapRecord's `Auth\Guard::bind()` catches every exception from the bind and rewraps it.
 * The probe therefore cannot tell them apart; it must still fail closed and stay quiet
 * about the password. Reported separately: the operator is told to check credentials.
 */
test('an unreachable host fails the probe without leaking the bind password', function () {
    fakeLdapConnection()->getLdapConnection()->expect(
        LdapFake::operation('bind')->andThrow(new LdapRecordException("Can't contact LDAP server")),
    );

    $result = app(TestLdapConnectionAction::class)->execute(ldapProbeSettings());

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->message)->not->toContain(LDAP_BIND_SECRET)
        ->and($result->message)->not->toContain('cn=admin');
});

test('a bind that times out fails the probe without leaking the bind password', function () {
    fakeLdapConnection()->getLdapConnection()->expect(
        LdapFake::operation('bind')->andThrow(new LdapRecordException('Timed out')),
    );

    $result = app(TestLdapConnectionAction::class)->execute(ldapProbeSettings());

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->message)->not->toContain(LDAP_BIND_SECRET);
});

/**
 * StartTLS runs before the bind in `Auth\Guard::bind()`, so its failure is the one
 * directory error that reaches the action unwrapped. `Connection::connect()` documents
 * `@throws ConnectionException`; whatever LdapRecord raises there, the operator gets a
 * connection-level message and never a stack trace or the password.
 */
test('a StartTLS failure is reported as a connection failure', function () {
    fakeLdapConnection(['use_tls' => false, 'use_starttls' => true, 'port' => 389])
        ->getLdapConnection()
        ->expect(LdapFake::operation('startTLS')->andThrow(new ConnectionException('TLS handshake failed')));

    $result = app(TestLdapConnectionAction::class)->execute(
        ldapProbeSettings(['encryption' => LdapEncryptionEnum::StartTls, 'port' => 389]),
    );

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->message)->toBe('Could not connect to the LDAP server.')
        ->and($result->message)->not->toContain(LDAP_BIND_SECRET);
});

test('an unexpected directory error is reported without exposing its detail', function () {
    fakeLdapConnection(['use_tls' => false, 'use_starttls' => true, 'port' => 389])
        ->getLdapConnection()
        ->expect(LdapFake::operation('startTLS')->andThrow(new LdapRecordException('certificate verify failed for cn=admin,dc=local,dc=com')));

    $result = app(TestLdapConnectionAction::class)->execute(
        ldapProbeSettings(['encryption' => LdapEncryptionEnum::StartTls, 'port' => 389]),
    );

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->message)->toBe('LDAP connection test failed.')
        ->and($result->message)->not->toContain('cn=admin');
});

test('the probe refuses an enabled connector without TLS or StartTLS before it binds', function () {
    fakeLdapConnection()->getLdapConnection()->expect(
        LdapFake::operation('bind')->andReturnResponse(),
    );

    $result = app(TestLdapConnectionAction::class)->execute(
        ldapProbeSettings(['encryption' => LdapEncryptionEnum::None, 'port' => 389]),
    );

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->message)->toBe('TLS (LDAPS) or StartTLS is required.');
});

test('the probe refuses settings without hosts or a base DN', function () {
    expect(app(TestLdapConnectionAction::class)->execute(ldapProbeSettings(['hosts' => []])))
        ->isSuccessful->toBeFalse()
        ->message->toBe('Hosts and base DN are required.');

    expect(app(TestLdapConnectionAction::class)->execute(ldapProbeSettings(['baseDn' => ''])))
        ->isSuccessful->toBeFalse()
        ->message->toBe('Hosts and base DN are required.');
});

test('the probe falls back to the stored instance settings when none are passed', function () {
    enableLdapSettings(['bindPassword' => LDAP_BIND_SECRET]);

    fakeLdapConnection()->getLdapConnection()->expect(
        LdapFake::operation('bind')->andThrow(new BindException('Invalid credentials')),
    );

    $result = app(TestLdapConnectionAction::class)->execute();

    expect($result->isSuccessful)->toBeFalse()
        ->and($result->message)->toBe('Bind failed with the configured credentials.');
});

test('instance settings are applied to the LdapRecord connection', function () {
    Container::getInstance()->flush();

    $connection = app(LdapConnectionService::class)->applyFromSettings(ldapProbeSettings([
        'hosts' => ['ldap.example.test', 'ldap2.example.test'],
        'encryption' => LdapEncryptionEnum::StartTls,
        'port' => 389,
    ]));

    expect($connection)->toBeInstanceOf(Connection::class)
        ->and(Container::getInstance()->getConnection('default'))->toBe($connection)
        ->and(config('ldap.connections.default.hosts'))->toBe(['ldap.example.test', 'ldap2.example.test'])
        ->and(config('ldap.connections.default.port'))->toBe(389)
        ->and(config('ldap.connections.default.username'))->toBe('cn=admin,dc=local,dc=com')
        ->and(config('ldap.connections.default.use_starttls'))->toBeTrue()
        ->and(config('ldap.connections.default.use_tls'))->toBeFalse()
        ->and(config('ldap.logging.enabled'))->toBeFalse();
});

test('LDAP operations are never written to the application log', function () {
    $logger = Log::spy();

    Container::getInstance()->flush();
    app(LdapConnectionService::class)->applyFromSettings(ldapProbeSettings());

    expect(config('ldap.logging.enabled'))->toBeFalse();

    $logger->shouldNotHaveReceived('info');
    $logger->shouldNotHaveReceived('debug');
});

test('applying settings without hosts or a base DN is refused', function () {
    Container::getInstance()->flush();

    expect(fn () => app(LdapConnectionService::class)->applyFromSettings(ldapProbeSettings(['hosts' => []])))
        ->toThrow(RuntimeException::class, 'LDAP hosts are not configured.');

    expect(fn () => app(LdapConnectionService::class)->applyFromSettings(ldapProbeSettings(['baseDn' => ''])))
        ->toThrow(RuntimeException::class, 'LDAP base DN is not configured.');
});
