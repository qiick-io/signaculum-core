<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\OrganizationMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
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

test('a member with 2FA can finish authorization when the org requires MFA', function () {
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
    $authorization = $this->actingAs($member)
        ->get(authorizeUrl($client, pkcePair()['challenge']));

    expect(in_array($authorization->status(), [200, 302], true))->toBeTrue();

    if ($authorization->status() === 302) {
        expect($authorization->headers->get('Location'))->not->toBe(route('security.edit'));
    } else {
        $authorization->assertInertia(fn (Assert $page) => $page->component('oauth/authorize'));
    }
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

test('org MFA gating does not invent mfa in amr', function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $owner = User::factory()->create();
    $member = User::factory()->withTwoFactor()->create([
        'email' => 'org-mfa-amr@example.test',
        'password' => bcrypt('password'),
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

    // Sign in with password only (2FA is enrolled but not challenged here): gate passes
    // because the account has 2FA, while amr must stay ['pwd'] without inventing mfa.
    $this->post('/login', [
        'email' => 'org-mfa-amr@example.test',
        'password' => 'password',
    ]);

    // withTwoFactor users may be redirected to the challenge; complete without claiming we did MFA
    // by acting as the user and seeding password-only methods.
    $this->actingAs($member);
    session(['oidc.authentication_methods' => ['pwd']]);

    $client = createOidcClient();
    $pkce = pkcePair();
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    $claims = verifiedIdToken(redeemCode($client, $code, $pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('amr'))->toBe(['pwd'])
        ->and($claims->get('amr'))->not->toContain('mfa');
});
