<?php

declare(strict_types=1);

use App\Enums\AuthenticationMethodEnum;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;

/**
 * #169: an MFA requirement lives in stored data (`organizations.is_mfa_required`,
 * the `two_factor_required` setting), so it never depends on which second-factor
 * features `config/fortify.php` enables. Turning TOTP off must not stop enforcement;
 * turning every method off must refuse explicitly instead of passing or looping.
 */
function enableSecondFactorFeatures(bool $isTotpEnabled, bool $isPasskeysEnabled): void
{
    config(['fortify.features' => array_values(array_filter([
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        $isTotpEnabled ? Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]) : null,
        $isPasskeysEnabled ? Features::passkeys(['confirmPassword' => true]) : null,
    ]))]);
}

function memberOfMfaRequiredOrganization(User $member): User
{
    $organization = Organization::factory()->create(['is_mfa_required' => true]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    return $member;
}

function registerPasskeyFor(User $user, string $credentialId, array $transports): void
{
    $passkey = new Passkey([
        'name' => 'Key '.$credentialId,
        'credential_id' => $credentialId,
        'credential' => ['transports' => $transports, 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $user->getKey();
    $passkey->save();
}

/**
 * @param  list<AuthenticationMethodEnum>  $authenticationMethods
 */
function recordSessionMethods(User $user, array $authenticationMethods): void
{
    session([
        'oidc.authentication_subject' => (string) $user->getKey(),
        'oidc.authentication_methods' => array_map(
            fn (AuthenticationMethodEnum $authenticationMethod): string => $authenticationMethod->value,
            $authenticationMethods,
        ),
    ]);
}

describe('organisation MFA gate', function () {
    test('a member without a second factor is sent to enrol when TOTP is off but passkeys are on', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);
        $member = memberOfMfaRequiredOrganization(User::factory()->create());

        $this->actingAs($member);
        recordSessionMethods($member, [AuthenticationMethodEnum::Password]);

        $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
            ->assertRedirect(route('security.edit'));
    });

    test('a passkey session satisfies the requirement when TOTP is off', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);
        $member = memberOfMfaRequiredOrganization(User::factory()->create());
        registerPasskeyFor($member, 'passkey-totp-off', ['internal']);

        $this->actingAs($member);
        recordSessionMethods($member, [AuthenticationMethodEnum::SoftwareKey]);

        $client = createOidcClient();
        $pkce = pkcePair();

        expect(approveAuthorization($client, $pkce['challenge'])['code'] ?? null)->toBeString();
    });

    test('a passkey member with a password-only session is stepped up when TOTP is off', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);
        $member = memberOfMfaRequiredOrganization(User::factory()->create());
        registerPasskeyFor($member, 'passkey-pwd-session', ['usb']);

        $this->actingAs($member);
        recordSessionMethods($member, [AuthenticationMethodEnum::Password]);

        $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
            ->assertRedirect('/login');

        expect(auth()->check())->toBeFalse();
    });

    test('a TOTP-only member is sent to enrol a passkey, not stepped up into a login that cannot challenge TOTP', function () {
        // Fortify drops the TOTP challenge from login when the feature is off, so a
        // step-up would come back as `pwd` again: a redirect loop.
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);
        $member = memberOfMfaRequiredOrganization(User::factory()->withTwoFactor()->create());

        $this->actingAs($member);
        recordSessionMethods($member, [AuthenticationMethodEnum::Password]);

        $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
            ->assertRedirect(route('security.edit'));

        expect(auth()->id())->toBe($member->getKey());
    });

    test('with no second-factor method enabled the request is refused with an explanation and no code', function (bool $hasEnrolledTotp, array $sessionMethods) {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: false);
        $userFactory = $hasEnrolledTotp ? User::factory()->withTwoFactor() : User::factory();
        $member = memberOfMfaRequiredOrganization($userFactory->create());

        $this->actingAs($member);
        recordSessionMethods($member, $sessionMethods);

        $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/error-page')
                ->where('status', 403)
                ->where('reason', 'secondFactorUnavailable'));

        expect(auth()->id())->toBe($member->getKey());
    })->with([
        'no factor, password session' => [false, [AuthenticationMethodEnum::Password]],
        'TOTP enrolled, password session' => [true, [AuthenticationMethodEnum::Password]],
        'TOTP enrolled, session proved before the config change' => [true, [AuthenticationMethodEnum::Password, AuthenticationMethodEnum::OneTimePassword, AuthenticationMethodEnum::MultipleFactors]],
    ]);

    test('with no second-factor method enabled prompt=none returns access_denied to the client', function () {
        config(['oidc.issuer' => 'https://id.example.test']);
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: false);
        $member = memberOfMfaRequiredOrganization(User::factory()->create());

        $this->actingAs($member);
        recordSessionMethods($member, [AuthenticationMethodEnum::Password]);

        $response = $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge'], ['prompt' => 'none']));
        $query = redirectQuery($response->headers->get('Location'));

        expect($query['error'])->toBe('access_denied')
            ->and($query['state'])->toBe('state-123')
            ->and($query['iss'])->toBe('https://id.example.test')
            ->and($query)->not->toHaveKey('code')
            ->and(auth()->id())->toBe($member->getKey());
    });
});

describe('instance-wide two-factor requirement', function () {
    beforeEach(function () {
        app(SettingsRepositoryService::class)->save(['two_factor_required' => true]);
    });

    test('a user without a second factor is sent to enrol when TOTP is off but passkeys are on', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'));
    });

    test('a user with a passkey passes when TOTP is off', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);
        $user = User::factory()->create();
        registerPasskeyFor($user, 'instance-passkey', ['internal']);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    });

    test('a TOTP-only user is sent to enrol a passkey when TOTP is off', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);

        $this->actingAs(User::factory()->withTwoFactor()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'));
    });

    test('with no second-factor method enabled the app is refused with an explanation, not a redirect', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: false);

        $this->actingAs(User::factory()->withTwoFactor()->create());

        $this->get(route('dashboard'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page
                ->component('errors/error-page')
                ->where('reason', 'secondFactorUnavailable'));

        $this->get(authorizeUrl(createOidcClient(), pkcePair()['challenge']))->assertForbidden();
        // Exempt routes stay reachable (the security page then asks for the password).
        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
        $this->post(route('logout'))->assertRedirect();
    });
});

describe('operator warning', function () {
    test('the authentication settings page lists the organisations no method can satisfy', function () {
        // Organisation requirements bite only at `/oauth/authorize`, so the admin still
        // reaches this page and sees the gap before a member is refused. (An instance-wide
        // requirement refuses every page at once, with its own explanation.)
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: false);
        Organization::factory()->create(['name' => 'Secure Org', 'is_mfa_required' => true]);
        Organization::factory()->create(['name' => 'Relaxed Org', 'is_mfa_required' => false]);

        $this->actingAs(instanceAdmin())
            ->get(route('instance.security.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('unavailableSecondFactor.mfaRequiredOrganizationNames', ['Secure Org']));
    });

    test('the warning is absent while a second-factor method is enabled', function () {
        enableSecondFactorFeatures(isTotpEnabled: false, isPasskeysEnabled: true);
        Organization::factory()->create(['is_mfa_required' => true]);

        $this->actingAs(instanceAdmin())
            ->get(route('instance.security.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('unavailableSecondFactor', null));
    });
});
