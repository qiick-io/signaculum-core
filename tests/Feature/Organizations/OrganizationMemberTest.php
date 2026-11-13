<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;

function organizationOwnedBy(User $owner, array $attributes = []): Organization
{
    $organization = Organization::factory()->create($attributes);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $owner->getKey(),
        'roles' => [OrganizationRoleEnum::Owner->value],
    ]);

    return $organization;
}

test('an owner adds a member with several roles', function () {
    $owner = User::factory()->create();
    $newMember = User::factory()->create();
    $organization = organizationOwnedBy($owner, ['slug' => 'multi-role']);

    $this->actingAs($owner)
        ->post(route('organizations.members.store', $organization), [
            'user_id' => $newMember->getKey(),
            'roles' => [
                OrganizationRoleEnum::Admin->value,
                OrganizationRoleEnum::Billing->value,
            ],
        ])
        ->assertRedirect(route('organizations.show', $organization));

    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $newMember->getKey())
        ->firstOrFail();

    expect($membership->roles)->toBe([
        OrganizationRoleEnum::Admin->value,
        OrganizationRoleEnum::Billing->value,
    ]);
});

test('an owner updates member roles', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = organizationOwnedBy($owner, ['slug' => 'roles-update']);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($owner)
        ->put(route('organizations.members.update', [$organization, $member]), [
            'roles' => [
                OrganizationRoleEnum::Admin->value,
                OrganizationRoleEnum::Member->value,
            ],
        ])
        ->assertRedirect(route('organizations.show', $organization));

    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $member->getKey())
        ->firstOrFail();

    expect($membership->roles)->toBe([
        OrganizationRoleEnum::Admin->value,
        OrganizationRoleEnum::Member->value,
    ]);
});

test('the last owner cannot be removed or demoted', function () {
    $owner = User::factory()->create();
    $organization = organizationOwnedBy($owner, ['slug' => 'last-owner']);

    $this->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $owner]))
        ->assertRedirect()
        ->assertSessionHasErrors('member');

    expect(OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $owner->getKey())
        ->exists())->toBeTrue();

    $this->actingAs($owner)
        ->from(route('organizations.show', $organization))
        ->put(route('organizations.members.update', [$organization, $owner]), [
            'roles' => [OrganizationRoleEnum::Admin->value],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('roles');

    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $owner->getKey())
        ->firstOrFail();

    expect($membership->roles)->toBe([OrganizationRoleEnum::Owner->value]);
});

test('a second owner can be removed after another owner remains', function () {
    $owner = User::factory()->create();
    $coOwner = User::factory()->create();
    $organization = organizationOwnedBy($owner, ['slug' => 'two-owners']);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $coOwner->getKey(),
        'roles' => [OrganizationRoleEnum::Owner->value],
    ]);

    $this->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $coOwner]))
        ->assertRedirect(route('organizations.show', $organization));

    expect(OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $coOwner->getKey())
        ->exists())->toBeFalse();

    expect($organization->ownerCount())->toBe(1);
});

test('a plain member cannot manage other members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $other = User::factory()->create();
    $organization = organizationOwnedBy($owner, ['slug' => 'no-manage']);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($member)
        ->post(route('organizations.members.store', $organization), [
            'user_id' => $other->getKey(),
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertForbidden();
});

test('adding an existing member fails validation', function () {
    $owner = User::factory()->create();
    $organization = organizationOwnedBy($owner, ['slug' => 'duplicate']);

    $this->actingAs($owner)
        ->from(route('organizations.show', $organization))
        ->post(route('organizations.members.store', $organization), [
            'user_id' => $owner->getKey(),
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('user_id');
});
