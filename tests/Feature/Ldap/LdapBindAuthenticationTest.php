<?php

declare(strict_types=1);

use App\Jobs\SendBackChannelLogoutJob;
use App\Ldap\DirectoryUser;
use App\Models\EmailLoginCode;
use App\Models\User;
use App\Notifications\EmailLoginCodeNotification;
use App\Services\LdapUserSyncService;
use App\Services\SettingsRepositoryService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use LdapRecord\Container;
use LdapRecord\Laravel\Testing\DirectoryEmulator;
use LdapRecord\LdapRecordException;
use LdapRecord\Models\Attributes\AccountControl;
use LdapRecord\Testing\ConnectionFake;
use LdapRecord\Testing\LdapFake;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

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

/**
 * @param  list<string>  $transports
 */
function ldapSavedPasskeyFor(User $user, string $credentialId, array $transports): Passkey
{
    $passkey = new Passkey([
        'name' => 'Key '.$credentialId,
        'credential_id' => $credentialId,
        'credential' => ['transports' => $transports, 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $user->getKey();
    $passkey->save();

    return $passkey;
}

/**
 * Stub VerifyPasskey so the login controller reaches allowsLogin() without WebAuthn crypto.
 */
function fakePasskeyVerification(Passkey $passkey): void
{
    app()->instance(VerifyPasskey::class, new class($passkey) extends VerifyPasskey
    {
        public function __construct(private readonly Passkey $verifiedPasskey) {}

        public function __invoke(
            PublicKeyCredential $credential,
            PublicKeyCredentialRequestOptions $options,
            ?PasskeyUser $user = null,
        ): Passkey {
            PasskeyVerified::dispatch($this->verifiedPasskey->user, $this->verifiedPasskey);

            return $this->verifiedPasskey;
        }
    });
}

/**
 * @return array{id: string, rawId: string, type: string, response: array{clientDataJSON: string, authenticatorData: string, signature: string}}
 */
function fakePasskeyCredentialPayload(string $credentialIdBase64): array
{
    return [
        'id' => $credentialIdBase64,
        'rawId' => $credentialIdBase64,
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded((string) json_encode([
                'type' => 'webauthn.get',
                'challenge' => Base64UrlSafe::encodeUnpadded(random_bytes(32)),
                'origin' => config('app.url'),
            ])),
            'authenticatorData' => Base64UrlSafe::encodeUnpadded(str_repeat("\0", 32)."\x05".str_repeat("\0", 4)),
            'signature' => Base64UrlSafe::encodeUnpadded('signature'),
        ],
    ];
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

test('an LDAP user cannot receive or redeem an email login code', function () {
    app(SettingsRepositoryService::class)->save(['email_code_login_enabled' => true]);
    Notification::fake();

    $user = ldapLinkedUser(['email' => 'ldap-otp@example.com']);

    $this->post(route('login.email-code.store'), ['email' => $user->email])
        ->assertSessionHasErrors('email');

    Notification::assertNothingSent();
    expect(EmailLoginCode::query()->count())->toBe(0);

    EmailLoginCode::query()->create([
        'email' => $user->email,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->post(route('login.email-code.verify'), [
        'email' => $user->email,
        'code' => '123456',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an LDAP user cannot sign in with a passkey as the first factor', function () {
    $user = ldapLinkedUser(['email' => 'ldap-passkey@example.com']);
    $passkey = ldapSavedPasskeyFor($user, 'ldap-first-factor-key', ['internal']);
    fakePasskeyVerification($passkey);

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->postJson(route('passkey.login'), [
        'credential' => fakePasskeyCredentialPayload('bGRhcC1maXJzdC1mYWN0b3Ita2V5'),
    ])->assertStatus(422);

    $this->assertGuest();
});

test('a directory-disabled LDAP user still active locally cannot use email code or passkey before sync', function () {
    app(SettingsRepositoryService::class)->save(['email_code_login_enabled' => true]);
    Notification::fake();

    // Disabled in AD conceptually, but sync has not run yet — local row stays active.
    $user = ldapLinkedUser([
        'email' => 'pre-sync-disabled@example.com',
        'is_active' => true,
    ]);
    $passkey = ldapSavedPasskeyFor($user, 'pre-sync-disabled-key', ['usb']);

    $this->post(route('login.email-code.store'), ['email' => $user->email])
        ->assertSessionHasErrors('email');
    Notification::assertNothingSent();

    fakePasskeyVerification($passkey);

    $this->getJson(route('passkey.login-options'))->assertOk();
    $this->postJson(route('passkey.login'), [
        'credential' => fakePasskeyCredentialPayload('cHJlLXN5bmMtZGlzYWJsZWQta2V5'),
    ])->assertStatus(422);

    $this->assertGuest();
});

test('password confirmation for an LDAP user binds to the directory', function () {
    enableLdapSettings();

    $user = ldapLinkedUser([
        'email' => 'confirm@example.com',
        'password' => null,
    ]);

    Container::getConnection('default')->getLdapConnection()->expect(
        LdapFake::operation('bind')->once()->with([(string) $user->ldap_dn, 'DirectoryPass1!'])->andReturnResponse(),
    );

    $this->actingAs($user)
        ->post(route('password.confirm.store'), ['password' => 'DirectoryPass1!'])
        ->assertSessionHasNoErrors();

    expect(session('auth.password_confirmed_at'))->not->toBeNull();
});

test('password confirmation for an LDAP user refuses a wrong directory password', function () {
    enableLdapSettings();

    $user = ldapLinkedUser([
        'email' => 'confirm-wrong@example.com',
        'password' => 'local-password',
    ]);

    // Local hash would match; directory bind must still refuse.
    $this->actingAs($user)
        ->from(route('password.confirm'))
        ->post(route('password.confirm.store'), ['password' => 'local-password'])
        ->assertSessionHasErrors('password');

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

test('a local non-LDAP user still signs in with an email code and a passkey', function () {
    app(SettingsRepositoryService::class)->save(['email_code_login_enabled' => true]);
    Notification::fake();

    $emailUser = User::factory()->create(['email' => 'local-otp@example.com', 'ldap_guid' => null]);

    $this->post(route('login.email-code.store'), ['email' => $emailUser->email])
        ->assertRedirect(route('login.email-code.show', ['email' => $emailUser->email]));

    $plainCode = '';
    Notification::assertSentTo(
        $emailUser,
        EmailLoginCodeNotification::class,
        function (EmailLoginCodeNotification $notification) use (&$plainCode): bool {
            $plainCode = $notification->plainCode;

            return strlen($plainCode) === 6;
        },
    );

    $this->post(route('login.email-code.verify'), [
        'email' => $emailUser->email,
        'code' => $plainCode,
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($emailUser);
    $this->post(route('logout'));
    $this->assertGuest();

    $passkeyUser = User::factory()->create(['email' => 'local-passkey@example.com', 'ldap_guid' => null]);
    fakePasskeyVerification(ldapSavedPasskeyFor($passkeyUser, 'local-passkey-key', ['internal']));

    $this->getJson(route('passkey.login-options'))->assertOk();
    $this->postJson(route('passkey.login'), [
        'credential' => fakePasskeyCredentialPayload('bG9jYWwtcGFzc2tleS1rZXk'),
    ])->assertOk();

    $this->assertAuthenticatedAs($passkeyUser);
});
