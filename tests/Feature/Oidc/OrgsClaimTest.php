<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
    $this->client = createOidcClient();
    $this->pkce = pkcePair();
});

/**
 * Attach the user to an organisation with the given roles.
 *
 * @param  list<string>  $roles
 */
function membershipIn(User $user, array $attributes, array $roles): Organization
{
    $organization = Organization::factory()->create($attributes);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $user->getKey(),
        'roles' => $roles,
    ]);

    return $organization;
}

test('discovery advertises the qiick.orgs scope and orgs claim', function () {
    $document = $this->getJson('/.well-known/openid-configuration')->json();

    expect($document['scopes_supported'])->toContain('qiick.orgs')
        ->and($document['claims_supported'])->toContain('orgs');
});

test('the consent screen lists the qiick.orgs scope when requested', function () {
    $this->actingAs($this->user);

    $this->get(authorizeUrl($this->client, $this->pkce['challenge'], [
        'scope' => 'openid qiick.orgs',
    ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('oauth/authorize')
            ->has('scopes', 2)
            ->where('scopes.1.id', 'qiick.orgs')
            ->where('scopes.1.description', 'See your organisations and roles'));
});

test('id_token and userinfo emit orgs when qiick.orgs is granted', function () {
    $acme = membershipIn($this->user, ['slug' => 'acme', 'name' => 'Acme'], [
        OrganizationRoleEnum::Owner->value,
        OrganizationRoleEnum::Billing->value,
    ]);
    membershipIn($this->user, ['slug' => 'beta', 'name' => 'Beta'], [
        OrganizationRoleEnum::Member->value,
    ]);

    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'scope' => 'openid qiick.orgs',
    ])['code'];

    $tokenResponse = redeemCode($this->client, $code, $this->pkce['verifier'])->assertOk();
    $idTokenClaims = verifiedIdToken($tokenResponse->json('id_token'))->claims();

    $expectedOrgs = [
        [
            'id' => $acme->getKey(),
            'slug' => 'acme',
            'roles' => [
                OrganizationRoleEnum::Owner->value,
                OrganizationRoleEnum::Billing->value,
            ],
        ],
        [
            'id' => Organization::query()->where('slug', 'beta')->value('id'),
            'slug' => 'beta',
            'roles' => [OrganizationRoleEnum::Member->value],
        ],
    ];

    expect($idTokenClaims->get('orgs'))->toBe($expectedOrgs);

    app('auth')->forgetGuards();

    $this->withToken($tokenResponse->json('access_token'))
        ->get('/oauth/userinfo')
        ->assertOk()
        ->assertExactJson([
            'sub' => $this->user->subject,
            'orgs' => $expectedOrgs,
        ]);
});

test('orgs is an empty list when the user has no memberships', function () {
    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'scope' => 'openid qiick.orgs',
    ])['code'];

    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->get('orgs'))->toBe([]);
});

test('orgs is omitted without the qiick.orgs scope', function () {
    membershipIn($this->user, ['slug' => 'hidden'], [OrganizationRoleEnum::Admin->value]);

    $this->actingAs($this->user);
    $code = approveAuthorization($this->client, $this->pkce['challenge'], [
        'scope' => 'openid profile',
    ])['code'];

    $claims = verifiedIdToken(redeemCode($this->client, $code, $this->pkce['verifier'])->json('id_token'))->claims();

    expect($claims->has('orgs'))->toBeFalse();
});
