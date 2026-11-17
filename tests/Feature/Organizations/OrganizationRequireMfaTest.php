<?php

declare(strict_types=1);

use App\Enums\AuthenticationMethodEnum;
use App\Enums\OrganizationRoleEnum;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passkeys\Passkey;
use Spatie\Activitylog\Models\Activity;

test('an owner can require MFA for organisation members', function () {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, [
        'name' => 'Secure Org',
        'slug' => 'secure-org',
    ]);

    $this->actingAs($owner)
        ->put(route('organizations.update', $organization), [
            'name' => 'Secure Org',
            'slug' => 'secure-org',
            'is_mfa_required' => '1',
        ])
        ->assertRedirect(route('organizations.settings', $organization));

    expect($organization->refresh()->is_mfa_required)->toBeTrue();

    expect(Activity::query()
        ->where('event', 'organization_mfa_requirement_updated')
        ->where('subject_type', $organization->getMorphClass())
        ->where('subject_id', $organization->getKey())
        ->exists())->toBeTrue();
});

test('a plain member cannot change the MFA requirement', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'member-org']);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($member)
        ->put(route('organizations.update', $organization), [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'is_mfa_required' => '1',
        ])
        ->assertForbidden();

    expect($organization->refresh()->is_mfa_required)->toBeFalse();
});

test('settings page exposes the MFA requirement flag', function () {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'flag-org',
        'is_mfa_required' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('organizations.settings', $organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/settings')
            ->where('organization.is_mfa_required', true));
});

test('a member without 2FA is sent to security before authorizing when the org requires MFA', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'mfa-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $client = createOidcClient();
    $authorizeUrl = authorizeUrl($client, pkcePair()['challenge']);

    $this->actingAs($member)
        ->get($authorizeUrl)
        ->assertRedirect(route('security.edit'));

    expect(session('url.intended'))->toStartWith(url('/oauth/authorize'));
});

test('an enrolled member with only email-code amr is stepped up before authorizing', function () {
    $owner = User::factory()->create();
    $member = User::factory()->withTwoFactor()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'email-otp-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $client = createOidcClient();
    $authorizeUrl = authorizeUrl($client, pkcePair()['challenge']);

    $this->actingAs($member);
    session([
        OidcSessionService::SESSION_KEY => 'sid-before-step-up',
        'oidc.authentication_methods' => [AuthenticationMethodEnum::OneTimePassword->value],
    ]);

    $this->get($authorizeUrl)->assertRedirect('/login');

    expect(auth()->check())->toBeFalse()
        ->and(session('url.intended'))->toStartWith(url('/oauth/authorize'));
});

test('an enrolled member with password-only amr is stepped up before authorizing', function () {
    $owner = User::factory()->create();
    $member = User::factory()->withTwoFactor()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'pwd-only-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $client = createOidcClient();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [AuthenticationMethodEnum::Password->value]]);

    $this->get(authorizeUrl($client, pkcePair()['challenge']))
        ->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});

test('a password plus TOTP session can finish authorization when the org requires MFA', function () {
    $owner = User::factory()->create();
    $member = User::factory()->withTwoFactor()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'mfa-ready-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $client = createOidcClient();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [
        AuthenticationMethodEnum::Password->value,
        AuthenticationMethodEnum::OneTimePassword->value,
        AuthenticationMethodEnum::MultipleFactors->value,
    ]]);

    $authorization = $this->get(authorizeUrl($client, pkcePair()['challenge']));

    expect(in_array($authorization->status(), [200, 302], true))->toBeTrue();

    if ($authorization->status() === 302) {
        expect($authorization->headers->get('Location'))->not->toBe(route('security.edit'))
            ->and($authorization->headers->get('Location'))->not->toEndWith('/login');
    } else {
        $authorization->assertInertia(fn (Assert $page) => $page->component('oauth/authorize'));
    }
});

test('a hardware passkey session satisfies organisation MFA without inventing mfa in amr', function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $owner = User::factory()->create();
    $member = User::factory()->create(['email' => 'passkey-mfa@example.test']);
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'passkey-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $passkey = new Passkey([
        'name' => 'YubiKey',
        'credential_id' => 'hardware-org-mfa',
        'credential' => ['transports' => ['usb'], 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $member->getKey();
    $passkey->save();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [AuthenticationMethodEnum::HardwareKey->value]]);

    $client = createOidcClient();
    $pkce = pkcePair();
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($client, $code, $pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('amr'))->toBe([AuthenticationMethodEnum::HardwareKey->value])
        ->and($claims->get('amr'))->not->toContain(AuthenticationMethodEnum::MultipleFactors->value);
});

test('a platform passkey session satisfies organisation MFA', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'swk-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $passkey = new Passkey([
        'name' => 'Touch ID',
        'credential_id' => 'platform-org-mfa',
        'credential' => ['transports' => ['internal'], 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $member->getKey();
    $passkey->save();

    $client = createOidcClient();

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [AuthenticationMethodEnum::SoftwareKey->value]]);

    $authorization = $this->get(authorizeUrl($client, pkcePair()['challenge']));

    expect(in_array($authorization->status(), [200, 302], true))->toBeTrue();

    if ($authorization->status() === 302) {
        expect($authorization->headers->get('Location'))->not->toEndWith('/login');
    } else {
        $authorization->assertInertia(fn (Assert $page) => $page->component('oauth/authorize'));
    }
});

test('organisation MFA step-up does not fan out back-channel logout', function () {
    Queue::fake();

    $owner = User::factory()->create();
    $member = User::factory()->withTwoFactor()->create();
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'step-up-sid-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($member);
    session([
        OidcSessionService::SESSION_KEY => 'sid-org-mfa-step-up',
        'oidc.authentication_methods' => [AuthenticationMethodEnum::Password->value],
    ]);

    $client = createOidcClient();
    $this->get(authorizeUrl($client, pkcePair()['challenge']))->assertRedirect('/login');

    Queue::assertNothingPushed();
});

test('authorization is not blocked by org MFA when the user has no membership in a requiring org', function () {
    $owner = User::factory()->create();
    createOrganizationWithOwner($owner, [
        'slug' => 'other-mfa-org',
        'is_mfa_required' => true,
    ]);

    $outsider = User::factory()->create();
    $client = createOidcClient();

    $this->actingAs($outsider)
        ->get(authorizeUrl($client, pkcePair()['challenge']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('oauth/authorize'));
});

test('password plus TOTP org MFA authorization keeps truthful amr', function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $owner = User::factory()->create();
    $member = User::factory()->withTwoFactor()->create([
        'email' => 'org-mfa-amr@example.test',
    ]);
    $organization = createOrganizationWithOwner($owner, [
        'slug' => 'amr-org',
        'is_mfa_required' => true,
    ]);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($member);
    session(['oidc.authentication_methods' => [
        AuthenticationMethodEnum::Password->value,
        AuthenticationMethodEnum::OneTimePassword->value,
        AuthenticationMethodEnum::MultipleFactors->value,
    ]]);

    $client = createOidcClient();
    $pkce = pkcePair();
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($client, $code, $pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('amr'))->toBe([
        AuthenticationMethodEnum::Password->value,
        AuthenticationMethodEnum::OneTimePassword->value,
        AuthenticationMethodEnum::MultipleFactors->value,
    ]);
});
