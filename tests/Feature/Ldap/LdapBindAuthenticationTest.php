<?php

declare(strict_types=1);

use App\Jobs\SendBackChannelLogoutJob;
use App\Ldap\DirectoryUser;
use App\Models\User;
use App\Services\LdapUserSyncService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\LdapRecordException;
use LdapRecord\Models\Attributes\AccountControl;
use LdapRecord\Testing\ConnectionFake;
use LdapRecord\Testing\LdapFake;

beforeEach(function () {
    fakeLdapDirectory();
});

afterEach(function () {
    DirectoryEmulator::tearDown();
    Container::getInstance()->flush();
});

/**
 * An LDAP-linked user whose local password must never authenticate (#187).
 *
 * @param  array<string, mixed>  $attributes
 */
function ldapLinkedUser(array $attributes = []): User
{
    $guid = Str::lower((string) Str::uuid());
    $distinguishedName = 'cn=Directory Person,dc=local,dc=com';

    return User::factory()->create(array_merge([
        'email' => 'directory@example.com',
        'password' => 'local-password',
        'ldap_guid' => $guid,
        'ldap_dn' => $distinguishedName,
        'email_verified_at' => now(),
        'is_active' => true,
    ], $attributes));
}

/**
 * Expect successful binds for the given DN and password on the emulated connection.
 *
 * Fortify runs `authenticateUsing` twice per login (2FA gate, then AttemptToAuthenticate),
 * so the directory bind must succeed at least twice in one request.
 */
function expectDirectoryBind(string $distinguishedName, string $password): void
{
    Container::getConnection('default')->getLdapConnection()->expect(
        LdapFake::operation('bind')->twice()->with([$distinguishedName, $password])->andReturnResponse(),
    );
}

test('an LDAP user cannot sign in with a local password', function () {
    enableLdapSettings();

    $user = ldapLinkedUser([
        'password' => 'local-password',
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'local-password',
    ]);

    $this->assertGuest();
});

test('password reset is refused for an LDAP user', function () {
    Notification::fake();

    $user = ldapLinkedUser();

    $response = $this->post(route('password.email'), [
        'email' => $user->email,
    ]);

    $response->assertSessionHasErrors('email');
    Notification::assertNotSentTo($user, ResetPassword::class);
});

test('setting a password from security settings is refused for an LDAP user', function () {
    $user = ldapLinkedUser([
        'password' => 'local-password',
    ]);

    $response = $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'local-password',
        'password' => 'BrandNewPass1!',
        'password_confirmation' => 'BrandNewPass1!',
    ]);

    $response->assertSessionHasErrors('password');
    expect($user->refresh()->password)->not->toBeNull();
});

test('the correct directory password signs an LDAP user in', function () {
    enableLdapSettings();

    $user = ldapLinkedUser([
        'password' => 'local-password',
    ]);

    expectDirectoryBind((string) $user->ldap_dn, 'DirectoryPass1!');

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'DirectoryPass1!',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('a wrong directory password is refused for an LDAP user', function () {
    enableLdapSettings();

    // Local hash deliberately matches the submitted string so today's Hash::check path
    // would accept it; bind-on-login must still refuse when the directory rejects the bind.
    $user = ldapLinkedUser([
        'password' => 'wrong-directory-password',
    ]);

    // No bind expectation: DirectoryEmulator fails the bind (wrong credentials).
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-directory-password',
    ]);

    $this->assertGuest();
});

test('a directory-disabled account matching the filter is deactivated and cannot authenticate', function () {
    Queue::fake();
    Notification::fake();
    enableLdapSettings();

    $guid = (string) Str::uuid();
    $user = ldapLinkedUser([
        'email' => 'disabled@example.com',
        'ldap_guid' => Str::lower($guid),
        'ldap_dn' => 'cn=Disabled Person,dc=local,dc=com',
        'password' => 'local-password',
    ]);

    $client = createOidcClient();
    $client->forceFill([
        'backchannel_logout_uri' => 'https://rp.example.test/backchannel-logout',
        'is_backchannel_logout_session_required' => true,
    ])->save();

    $this->actingAs($user);
    $pkce = pkcePair();
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    redeemCode($client, $code, $pkce['verifier'])->assertOk();

    expect(DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->where('revoked', false)->exists())->toBeTrue();

    DirectoryUser::create([
        'cn' => 'Disabled Person',
        'givenname' => 'Disabled',
        'sn' => 'Person',
        'mail' => 'disabled@example.com',
        'objectguid' => $guid,
        'userAccountControl' => (new AccountControl)->setAccountIsDisabled()->getValue(),
    ]);

    app(LdapUserSyncService::class)->sync();

    expect($user->refresh()->is_active)->toBeFalse()
        ->and(DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->where('revoked', false)->exists())->toBeFalse();

    Queue::assertPushed(SendBackChannelLogoutJob::class);

    $this->post(route('logout'));
    $this->assertGuest();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'local-password',
    ]);
    $this->assertGuest();

    // Inactive LDAP users are refused before a directory bind is attempted.
    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'DirectoryPass1!',
    ]);
    $this->assertGuest();

    $this->post(route('password.email'), [
        'email' => $user->email,
    ])->assertSessionHasErrors('email');

    Notification::assertNotSentTo($user, ResetPassword::class);
});

test('directory unreachable refuses LDAP users but not local users or the break-glass admin', function () {
    enableLdapSettings();

    $ldapUser = ldapLinkedUser([
        'email' => 'ldap@example.com',
    ]);
    $localUser = User::factory()->create([
        'email' => 'local@example.com',
        'password' => 'password',
        'ldap_guid' => null,
    ]);
    $breakGlassAdmin = User::factory()->instanceAdmin()->create([
        'email' => 'breakglass@example.com',
        'password' => 'password',
        'ldap_guid' => null,
    ]);

    DirectoryEmulator::tearDown();
    Container::getInstance()->flush();

    $connection = ConnectionFake::make([
        'hosts' => ['127.0.0.1'],
        'username' => 'cn=admin,dc=local,dc=com',
        'password' => 'bind-secret-value',
        'port' => 636,
        'base_dn' => 'dc=local,dc=com',
        'use_tls' => true,
        'use_starttls' => false,
        'use_sasl' => false,
    ])->shouldNotBeConnected();

    $connection->getLdapConnection()->expect(
        LdapFake::operation('bind')->andThrow(new LdapRecordException("Can't contact LDAP server")),
    );

    Container::getInstance()->addConnection($connection, 'default');

    $ldapResponse = $this->from(route('login'))->post(route('login.store'), [
        'email' => $ldapUser->email,
        'password' => 'any-password',
    ]);

    $ldapResponse->assertSessionHasErrors('email');
    $this->assertGuest();
    expect(session('errors')->get('email')[0] ?? '')->toContain('directory');

    $this->post(route('login.store'), [
        'email' => $localUser->email,
        'password' => 'password',
    ]);
    $this->assertAuthenticatedAs($localUser);
    $this->post(route('logout'));

    $this->post(route('login.store'), [
        'email' => $breakGlassAdmin->email,
        'password' => 'password',
    ]);
    $this->assertAuthenticatedAs($breakGlassAdmin);
});

test('sync creates LDAP users without a usable local password', function () {
    enableLdapSettings();

    DirectoryUser::create([
        'cn' => 'New Person',
        'givenname' => 'New',
        'sn' => 'Person',
        'mail' => 'new@example.com',
        'objectguid' => (string) Str::uuid(),
    ]);

    app(LdapUserSyncService::class)->sync();

    $created = User::query()->where('email', 'new@example.com')->first();

    expect($created)->not->toBeNull()
        ->and($created?->ldap_guid)->not->toBeNull()
        ->and($created?->password)->toBeNull();
});
