<?php

declare(strict_types=1);

use App\Enums\AuthenticationMethodEnum;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Refusal paths of the organisation MFA gate (#131): every way a session can
 * reach `/oauth/authorize` without a proven second factor must be stepped up.
 */
function mfaRequiredMember(?User $member = null): User
{
    $member ??= User::factory()->withTwoFactor()->create();
    $organization = Organization::factory()->create(['is_mfa_required' => true]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    return $member;
}

function savedPasskeyFor(User $user, string $credentialId, array $transports): Passkey
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

test('a session without any recorded amr is stepped up', function () {
    $member = mfaRequiredMember();

    $this->actingAs($member)
        ->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
        ->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});

test('malformed or unknown session amr values are stepped up', function (mixed $storedMethods) {
    $member = mfaRequiredMember();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => $storedMethods]);

    $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
        ->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
})->with([
    'string instead of a list' => ['mfa'],
    'upper-case value' => [['MFA']],
    'padded value' => [['mfa ']],
    'unknown method' => [['face']],
    'nested list' => [[['mfa']]],
    'integer' => [[1]],
    'null' => [null],
    'acr instead of amr' => [['urn:qiick:loa:mfa']],
]);

test('a client cannot inject amr through the authorization request', function () {
    $member = mfaRequiredMember();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [AuthenticationMethodEnum::Password->value]]);

    $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge'], [
        'amr' => 'mfa',
        'acr_values' => 'urn:qiick:loa:mfa',
        'oidc' => 'mfa',
    ]))->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});

test('a remember-me cookie session is stepped up even for an enrolled member', function () {
    $member = mfaRequiredMember();
    $member->forceFill(['remember_token' => 'remember-token-org-mfa'])->save();

    $recaller = $member->getKey().'|remember-token-org-mfa|'.$member->getAuthPassword();
    $cookieName = auth()->guard('web')->getRecallerName();

    $this->withCookie($cookieName, $recaller)
        ->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
        ->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});

test('the consent POST cannot be reached without a gated authorize GET', function () {
    $member = mfaRequiredMember();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [AuthenticationMethodEnum::Password->value]]);

    $this->post('/oauth/authorize', ['auth_token' => 'forged-auth-token'])
        ->assertForbidden();
});

test('a form POST authorization request is stepped up like the GET', function () {
    $member = mfaRequiredMember();
    $client = createOidcClient();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [AuthenticationMethodEnum::OneTimePassword->value]]);

    $parameters = [];
    parse_str((string) parse_url(authorizeUrl($client, pkcePair()['challenge']), PHP_URL_QUERY), $parameters);

    $this->post('/oauth/authorize', $parameters)->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});

test('a refused passkey login of another account does not satisfy a later password session', function () {
    // An attacker holds a passkey for an account Signaculum refuses to sign in (inactive),
    // and the password of a passkey-only member of an MFA-required organisation.
    $attacker = User::factory()->create(['is_active' => false]);
    $attackerPasskey = savedPasskeyFor($attacker, 'attacker-inactive-key', ['internal']);

    $victim = mfaRequiredMember(User::factory()->create(['email' => 'victim@example.test']));
    savedPasskeyFor($victim, 'victim-own-key', ['internal']);

    // Mirrors Laravel\Passkeys\Actions\VerifyPasskey: the assertion is valid, so
    // `PasskeyVerified` fires before PasskeyLoginController asks allowsLogin().
    app()->instance(VerifyPasskey::class, new class($attackerPasskey) extends VerifyPasskey
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

    $this->getJson(route('passkey.login-options'))->assertOk();

    $this->postJson(route('passkey.login'), ['credential' => [
        'id' => 'YXR0YWNrZXItaW5hY3RpdmUta2V5',
        'rawId' => 'YXR0YWNrZXItaW5hY3RpdmUta2V5',
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
    ]])->assertStatus(422);

    $this->assertGuest();

    $this->post('/login', ['email' => 'victim@example.test', 'password' => 'password']);
    $this->assertAuthenticatedAs($victim);

    $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
        ->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});
