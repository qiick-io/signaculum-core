<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function createOrganizationWithOwner(User $owner, array $attributes = []): Organization
{
    $organization = Organization::factory()->create($attributes);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $owner->getKey(),
        'roles' => [OrganizationRoleEnum::Owner->value],
    ]);

    return $organization;
}

test('a verified user creates an organisation and becomes its owner', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ])
        ->assertRedirect(route('organizations.show', 'acme-corp'));

    $organization = Organization::query()->where('slug', 'acme-corp')->firstOrFail();

    expect($organization->name)->toBe('Acme Corp');

    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $user->getKey())
        ->firstOrFail();

    expect($membership->roles)->toBe([OrganizationRoleEnum::Owner->value]);
});

test('slug is derived from the name when omitted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('organizations.store'), [
            'name' => 'Qiick Labs',
        ])
        ->assertRedirect();

    expect(Organization::query()->where('slug', 'qiick-labs')->exists())->toBeTrue();
});

test('members see their organisations; outsiders are forbidden', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, [
        'name' => 'Private Org',
        'slug' => 'private-org',
    ]);

    $this->actingAs($owner)
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/index')
            ->has('organizations', 1)
            ->where('organizations.0.slug', 'private-org'));

    $this->actingAs($outsider)
        ->get(route('organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/index')
            ->has('organizations', 0));

    $this->actingAs($outsider)
        ->get(route('organizations.show', $organization))
        ->assertForbidden();
});

test('an instance admin lists every organisation', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->instanceAdmin()->create();
    createOrganizationWithOwner($owner, ['name' => 'Hidden', 'slug' => 'hidden']);

    $this->actingAs($admin)
        ->get(route('organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations', 1)
            ->where('organizations.0.slug', 'hidden'));
});

test('an owner updates and deletes the organisation', function () {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, [
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    $this->actingAs($owner)
        ->put(route('organizations.update', $organization), [
            'name' => 'New Name',
            'slug' => 'new-name',
        ])
        ->assertRedirect(route('organizations.settings', 'new-name'));

    expect($organization->fresh()->name)->toBe('New Name');
    expect($organization->fresh()->slug)->toBe('new-name');

    $this->actingAs($owner)
        ->delete(route('organizations.destroy', 'new-name'))
        ->assertRedirect(route('organizations.index'));

    expect(Organization::query()->where('slug', 'new-name')->exists())->toBeFalse();
});

test('a member without manage rights cannot update the organisation', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'locked']);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($member)
        ->put(route('organizations.update', $organization), [
            'name' => 'Hacked',
            'slug' => 'locked',
        ])
        ->assertForbidden();
});
