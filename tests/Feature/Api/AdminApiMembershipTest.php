<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Exceptions\LastOrganizationOwnerException;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

/**
 * Admin REST API `/api/v1/organizations/{organization}/memberships` (#180).
 *
 * Membership is addressed by the member's `subject` (never the numeric id) and always under an
 * organisation, so the refusals that matter are the scope and a member who does not belong to
 * the organisation in the path.
 */
function membershipApiOrganization(string $slug, ?User $owner = null): Organization
{
    return createOrganizationWithOwner($owner ?? User::factory()->create(), ['slug' => $slug]);
}

function membershipRolesOf(Organization $organization, User $member): ?array
{
    return OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $member->getKey())
        ->first()?->roles;
}

it('refuses every memberships endpoint without a key, and changes no membership', function (): void {
    $owner = User::factory()->create();
    $organization = membershipApiOrganization('no-key-memberships', $owner);
    $outsider = User::factory()->create();

    foreach ([
        fn () => $this->getJson('/api/v1/organizations/no-key-memberships/memberships'),
        fn () => $this->postJson('/api/v1/organizations/no-key-memberships/memberships', [
            'user_id' => $outsider->subject,
            'roles' => [OrganizationRoleEnum::Member->value],
        ]),
        fn () => $this->patchJson('/api/v1/organizations/no-key-memberships/memberships/'.$owner->subject, [
            'roles' => [OrganizationRoleEnum::Member->value],
        ]),
        fn () => $this->deleteJson('/api/v1/organizations/no-key-memberships/memberships/'.$owner->subject),
    ] as $call) {
        $call()
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Missing API key.', 'error' => 'invalid_token']);
    }

    expect(membershipRolesOf($organization, $outsider))->toBeNull()
        ->and(membershipRolesOf($organization, $owner))->toBe([OrganizationRoleEnum::Owner->value]);
});

it('refuses a read key on the memberships write endpoints, and changes no membership', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);
    $owner = User::factory()->create();
    $organization = membershipApiOrganization('read-key-memberships', $owner);
    $outsider = User::factory()->create();

    $this->withToken($plain)
        ->postJson('/api/v1/organizations/read-key-memberships/memberships', [
            'user_id' => $outsider->subject,
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertForbidden()
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="organizations:write"');

    $this->withToken($plain)
        ->patchJson('/api/v1/organizations/read-key-memberships/memberships/'.$owner->subject, [
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertForbidden()
        ->assertJsonPath('scope', 'organizations:write');

    $this->withToken($plain)
        ->deleteJson('/api/v1/organizations/read-key-memberships/memberships/'.$owner->subject)
        ->assertForbidden()
        ->assertJsonPath('scope', 'organizations:write');

    // The read key may still list; nothing it was refused happened.
    $this->withToken($plain)->getJson('/api/v1/organizations/read-key-memberships/memberships')->assertOk();

    expect(membershipRolesOf($organization, $outsider))->toBeNull()
        ->and(membershipRolesOf($organization, $owner))->toBe([OrganizationRoleEnum::Owner->value]);
});

it('refuses a key scoped to another resource on the memberships list', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    membershipApiOrganization('wrong-scope-memberships');

    $this->withToken($plain)->getJson('/api/v1/organizations/wrong-scope-memberships/memberships')
        ->assertForbidden()
        ->assertJsonPath('scope', 'organizations:read');
});

it('answers 404 when the organization in the path does not exist', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $someone = User::factory()->create();

    $this->withToken($plain)->getJson('/api/v1/organizations/ghost-org/memberships')->assertNotFound();
    $this->withToken($plain)->postJson('/api/v1/organizations/ghost-org/memberships', [
        'user_id' => $someone->subject,
        'roles' => [OrganizationRoleEnum::Member->value],
    ])->assertNotFound();
    $this->withToken($plain)->patchJson('/api/v1/organizations/ghost-org/memberships/'.$someone->subject, [
        'roles' => [OrganizationRoleEnum::Member->value],
    ])->assertNotFound();
    $this->withToken($plain)->deleteJson('/api/v1/organizations/ghost-org/memberships/'.$someone->subject)->assertNotFound();
});

it('refuses a malformed add-member payload field by field', function (array $payload, array $invalidFields): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $organization = membershipApiOrganization('malformed-memberships');
    $membershipsBefore = OrganizationMembership::query()->where('organization_id', $organization->getKey())->count();

    $this->withToken($plain)
        ->postJson('/api/v1/organizations/malformed-memberships/memberships', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidFields);

    expect(OrganizationMembership::query()->where('organization_id', $organization->getKey())->count())
        ->toBe($membershipsBefore);
})->with([
    'nothing at all' => [[], ['user_id', 'roles']],
    'no user' => [['roles' => ['member']], ['user_id']],
    'unknown user' => [['user_id' => 'not-a-subject', 'roles' => ['member']], ['user_id']],
    'user id is an array' => [['user_id' => ['a', 'b'], 'roles' => ['member']], ['user_id']],
    'roles is not a list' => [['user_id' => 'not-a-subject', 'roles' => 'member'], ['roles']],
    'roles is empty' => [['user_id' => 'not-a-subject', 'roles' => []], ['roles']],
]);

it('refuses an unknown role on add and on update, and changes no roles', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $owner = User::factory()->create();
    $organization = membershipApiOrganization('bad-roles-memberships', $owner);
    $outsider = User::factory()->create();

    $this->withToken($plain)
        ->postJson('/api/v1/organizations/bad-roles-memberships/memberships', [
            'user_id' => $outsider->subject,
            'roles' => ['superuser'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles.0']);

    $this->withToken($plain)
        ->patchJson('/api/v1/organizations/bad-roles-memberships/memberships/'.$owner->subject, [
            'roles' => [OrganizationRoleEnum::Owner->value, 'instance-admin'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['roles.1']);

    expect(membershipRolesOf($organization, $outsider))->toBeNull()
        ->and(membershipRolesOf($organization, $owner))->toBe([OrganizationRoleEnum::Owner->value]);
});

it('refuses to add a user who is already a member', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $owner = User::factory()->create();
    membershipApiOrganization('duplicate-memberships', $owner);

    $this->withToken($plain)
        ->postJson('/api/v1/organizations/duplicate-memberships/memberships', [
            'user_id' => $owner->subject,
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['user_id']);

    expect(OrganizationMembership::query()
        ->where('user_id', $owner->getKey())
        ->count())->toBe(1);
});

it('will not reach across organisations to a membership that is not in the path', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $otherOwner = User::factory()->create();
    $otherOrganization = membershipApiOrganization('other-org-memberships', $otherOwner);
    membershipApiOrganization('target-org-memberships');

    // `other-org-memberships`' owner is addressed under a different organisation.
    $this->withToken($plain)
        ->patchJson('/api/v1/organizations/target-org-memberships/memberships/'.$otherOwner->subject, [
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertNotFound();

    $this->withToken($plain)
        ->deleteJson('/api/v1/organizations/target-org-memberships/memberships/'.$otherOwner->subject)
        ->assertNotFound();

    expect(membershipRolesOf($otherOrganization, $otherOwner))->toBe([OrganizationRoleEnum::Owner->value]);
});

it('answers 404 for a subject that belongs to no account', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    membershipApiOrganization('unknown-subject-memberships');

    $this->withToken($plain)
        ->patchJson('/api/v1/organizations/unknown-subject-memberships/memberships/not-a-subject', [
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertNotFound();

    $this->withToken($plain)
        ->deleteJson('/api/v1/organizations/unknown-subject-memberships/memberships/not-a-subject')
        ->assertNotFound();
});

it('lists the memberships of one organisation and nobody else', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);
    $owner = User::factory()->create(['email' => 'owner@example.test']);
    $organization = membershipApiOrganization('listed-memberships', $owner);
    $member = User::factory()->create(['email' => 'member@example.test']);
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value, OrganizationRoleEnum::Billing->value],
    ]);

    // A member of another organisation must not appear in this list.
    $elsewhere = User::factory()->create(['email' => 'elsewhere@example.test']);
    membershipApiOrganization('unlisted-memberships', $elsewhere);

    $response = $this->withToken($plain)
        ->getJson('/api/v1/organizations/listed-memberships/memberships')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.user_id', $owner->subject)
        ->assertJsonPath('data.0.email', 'owner@example.test')
        ->assertJsonPath('data.0.roles', [OrganizationRoleEnum::Owner->value])
        ->assertJsonPath('data.0.organization_id', $organization->getKey())
        ->assertJsonPath('data.1.email', 'member@example.test')
        ->assertJsonPath('data.1.roles', [
            OrganizationRoleEnum::Member->value,
            OrganizationRoleEnum::Billing->value,
        ])
        ->assertJsonStructure(['data' => [['created_at', 'updated_at']]]);

    expect($response->getContent())->not->toContain('elsewhere@example.test');
});

it('adds a member with the requested roles, de-duplicated', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $organization = membershipApiOrganization('add-memberships');
    $newMember = User::factory()->create(['email' => 'new@example.test']);

    $this->withToken($plain)
        ->postJson('/api/v1/organizations/add-memberships/memberships', [
            'user_id' => $newMember->subject,
            'roles' => [
                OrganizationRoleEnum::Member->value,
                OrganizationRoleEnum::Member->value,
                OrganizationRoleEnum::Billing->value,
            ],
        ])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $newMember->subject)
        ->assertJsonPath('data.email', 'new@example.test')
        ->assertJsonPath('data.roles', [
            OrganizationRoleEnum::Member->value,
            OrganizationRoleEnum::Billing->value,
        ]);

    expect(membershipRolesOf($organization, $newMember))->toBe([
        OrganizationRoleEnum::Member->value,
        OrganizationRoleEnum::Billing->value,
    ]);
});

it('replaces a member roles rather than adding to them', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $owner = User::factory()->create();
    $organization = membershipApiOrganization('update-memberships', $owner);
    $member = User::factory()->create();
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value, OrganizationRoleEnum::Billing->value],
    ]);

    $this->withToken($plain)
        ->patchJson('/api/v1/organizations/update-memberships/memberships/'.$member->subject, [
            'roles' => [OrganizationRoleEnum::Admin->value],
        ])
        ->assertOk()
        ->assertJsonPath('data.roles', [OrganizationRoleEnum::Admin->value]);

    expect(membershipRolesOf($organization, $member))->toBe([OrganizationRoleEnum::Admin->value]);
});

it('removes a member who is not the last owner', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $owner = User::factory()->create();
    $organization = membershipApiOrganization('remove-memberships', $owner);
    $member = User::factory()->create();
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->withToken($plain)
        ->deleteJson('/api/v1/organizations/remove-memberships/memberships/'.$member->subject)
        ->assertNoContent();

    expect(membershipRolesOf($organization, $member))->toBeNull()
        ->and(membershipRolesOf($organization, $owner))->toBe([OrganizationRoleEnum::Owner->value]);
});

/**
 * The organisation keeps its last owner. The web controller catches
 * {@see LastOrganizationOwnerException} and turns it into a validation error,
 * while the Admin API lets it escape as a 500 (reported with #180): what this test pins is the
 * refusal itself — the request does not succeed and the owner stays an owner. It passes
 * unchanged once the API returns the 422 it should.
 */
it('keeps the last owner of an organisation on both update and delete', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $owner = User::factory()->create();
    $organization = membershipApiOrganization('last-owner-memberships', $owner);

    $demotion = $this->withToken($plain)
        ->patchJson('/api/v1/organizations/last-owner-memberships/memberships/'.$owner->subject, [
            'roles' => [OrganizationRoleEnum::Member->value],
        ]);
    $removal = $this->withToken($plain)
        ->deleteJson('/api/v1/organizations/last-owner-memberships/memberships/'.$owner->subject);

    expect($demotion->isSuccessful())->toBeFalse()
        ->and($removal->isSuccessful())->toBeFalse()
        ->and(membershipRolesOf($organization, $owner))->toBe([OrganizationRoleEnum::Owner->value]);
});

it('lets the last owner be demoted once a second owner exists', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $owner = User::factory()->create();
    $organization = membershipApiOrganization('second-owner-memberships', $owner);
    $successor = User::factory()->create();

    $this->withToken($plain)
        ->postJson('/api/v1/organizations/second-owner-memberships/memberships', [
            'user_id' => $successor->subject,
            'roles' => [OrganizationRoleEnum::Owner->value],
        ])
        ->assertCreated();

    $this->withToken($plain)
        ->patchJson('/api/v1/organizations/second-owner-memberships/memberships/'.$owner->subject, [
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertOk();

    expect(membershipRolesOf($organization, $owner))->toBe([OrganizationRoleEnum::Member->value])
        ->and(membershipRolesOf($organization, $successor))->toBe([OrganizationRoleEnum::Owner->value]);
});
