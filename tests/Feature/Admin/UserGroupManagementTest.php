<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Assign group-management permissions, creating missing permission rows when needed.
 *
 * @param  list<PermissionEnum>  $permissions
 */
function grantGroupPermissions(User $user, array $permissions): void
{
    $role = Role::query()->firstOrCreate([
        'name' => 'groups-manager',
        'guard_name' => 'web',
    ]);

    foreach ($permissions as $permission) {
        Permission::query()->firstOrCreate([
            'name' => $permission->value,
            'guard_name' => 'web',
        ]);
    }

    $role->syncPermissions(array_map(
        fn (PermissionEnum $permission): string => $permission->value,
        $permissions,
    ));
    $user->assignRole($role);
}

it('redirects guests away from user group routes', function (): void {
    $this->get(route('admin.groups.index'))->assertRedirect(route('login'));
});

it('refuses users without group permissions with 403', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('admin.groups.index'))->assertForbidden();
});

it('lists groups with search, pagination and member ids for authorized users', function (): void {
    $this->withoutVite();

    $user = User::factory()->create();
    $member = User::factory()->create();
    grantGroupPermissions($user, [PermissionEnum::CanShowGroups]);
    $this->actingAs($user);

    $editors = UserGroup::factory()->create(['name' => 'Editors']);
    $editors->users()->sync([$member->id]);
    UserGroup::factory()->create(['name' => 'Support team']);

    $this->get(route('admin.groups.index', ['search' => 'editor']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/groups/index')
            ->has('groups.data', 1)
            ->where('groups.data.0.name', 'Editors')
            ->has('groups.data.0.roles')
            ->has('groups.data.0.user_ids', 1)
            ->where('groups.data.0.user_ids.0', $member->id)
            ->has('groups.data.0.users', 1)
            ->where('filters.search', 'editor')
            ->where('filters.trashed', false));
});

it('sorts the groups index by name, created_at and updated_at', function (): void {
    $this->withoutVite();

    $user = User::factory()->create();
    grantGroupPermissions($user, [PermissionEnum::CanShowGroups]);
    $this->actingAs($user);

    $zebra = UserGroup::factory()->create([
        'name' => 'Zebra',
        'updated_at' => now()->subDay(),
    ]);
    $alpha = UserGroup::factory()->create([
        'name' => 'Alpha',
        'updated_at' => now(),
    ]);

    $this->get(route('admin.groups.index', [
        'sort' => 'name',
        'direction' => 'asc',
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/groups/index')
            ->where('groups.data.0.id', $alpha->id)
            ->where('groups.data.1.id', $zebra->id)
            ->where('filters.sort', 'name')
            ->where('filters.direction', 'asc'));

    $this->get(route('admin.groups.index', [
        'sort' => 'updated_at',
        'direction' => 'desc',
    ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groups.data.0.id', $alpha->id)
            ->where('filters.sort', 'updated_at')
            ->where('filters.direction', 'desc'));
});

it('creates, updates and soft-deletes groups with pivots', function (): void {
    $user = User::factory()->create();
    $member = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'editor',
        'guard_name' => 'web',
        'is_assignable' => true,
    ]);

    grantGroupPermissions($user, [
        PermissionEnum::CanShowGroups,
        PermissionEnum::CanCreateGroups,
        PermissionEnum::CanEditGroups,
        PermissionEnum::CanDeleteGroups,
    ]);
    $this->actingAs($user);

    $this->post(route('admin.groups.store'), [
        'name' => 'Content',
        'description' => 'Content editors',
        'user_ids' => [$member->id],
        'role_ids' => [$role->id],
    ])->assertRedirect(route('admin.groups.index'));

    $group = UserGroup::query()->where('name', 'Content')->first();
    expect($group)->not->toBeNull();
    expect($group->users()->pluck('users.id')->all())->toContain($member->id);
    expect($group->roles()->pluck('roles.id')->all())->toContain($role->id);

    $this->put(route('admin.groups.update', $group), [
        'name' => 'Content updated',
        'description' => 'Updated',
        'user_ids' => [],
        'role_ids' => [$role->id],
    ])->assertRedirect(route('admin.groups.index'));

    $group->refresh();
    expect($group->name)->toBe('Content updated');
    expect($group->users()->count())->toBe(0);

    $this->delete(route('admin.groups.destroy', $group))
        ->assertRedirect(route('admin.groups.index'));

    expect(UserGroup::query()->whereKey($group->id)->exists())->toBeFalse();
    expect(UserGroup::query()->onlyTrashed()->whereKey($group->id)->exists())->toBeTrue();
});

it('does not wipe members when updating a group without user_ids', function (): void {
    $actor = User::factory()->create();
    $member = User::factory()->create();
    $role = Role::query()->create([
        'name' => 'keep-role',
        'guard_name' => 'web',
        'is_assignable' => true,
    ]);

    grantGroupPermissions($actor, [
        PermissionEnum::CanShowGroups,
        PermissionEnum::CanEditGroups,
    ]);
    $this->actingAs($actor);

    $group = UserGroup::factory()->create([
        'name' => 'Stable',
        'description' => 'Before',
    ]);
    $group->users()->sync([$member->id]);
    $group->roles()->sync([$role->id]);

    $this->put(route('admin.groups.update', $group), [
        'name' => 'Stable',
        'description' => 'After',
    ])->assertRedirect(route('admin.groups.index'));

    $group->refresh();
    expect($group->description)->toBe('After')
        ->and($group->users()->pluck('users.id')->all())->toContain($member->id)
        ->and($group->roles()->pluck('roles.id')->all())->toContain($role->id);
});

it('restores and force-deletes soft-deleted groups', function (): void {
    $actor = User::factory()->create();
    grantGroupPermissions($actor, [
        PermissionEnum::CanShowGroups,
        PermissionEnum::CanDeleteGroups,
        PermissionEnum::CanRestoreGroups,
        PermissionEnum::CanForceDeleteGroups,
    ]);
    $this->actingAs($actor);

    $victim = UserGroup::factory()->create(['name' => 'Victim']);

    $this->delete(route('admin.groups.destroy', $victim))
        ->assertRedirect(route('admin.groups.index'));

    expect($victim->fresh()->trashed())->toBeTrue();

    $this->withoutVite();
    $this->get(route('admin.groups.index', ['trashed' => 1]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/groups/index')
            ->where('filters.trashed', true)
            ->has('groups.data', 1)
            ->where('groups.data.0.name', 'Victim'));

    $this->post(route('admin.groups.restore', $victim))
        ->assertRedirect(route('admin.groups.index', ['trashed' => 1]));

    expect($victim->fresh()->trashed())->toBeFalse();

    $victim->delete();

    $this->delete(route('admin.groups.force-destroy', $victim))
        ->assertRedirect(route('admin.groups.index', ['trashed' => 1]));

    expect(UserGroup::query()->withTrashed()->find($victim->id))->toBeNull();
});

it('unions permissions from multiple group roles', function (): void {
    Permission::query()->firstOrCreate([
        'name' => PermissionEnum::CanShowGroups->value,
        'guard_name' => 'web',
    ]);
    Permission::query()->firstOrCreate([
        'name' => PermissionEnum::CanEditUsers->value,
        'guard_name' => 'web',
    ]);

    $showRole = Role::query()->create(['name' => 'group-show', 'guard_name' => 'web']);
    $showRole->givePermissionTo(PermissionEnum::CanShowGroups->value);

    $editRole = Role::query()->create(['name' => 'group-edit', 'guard_name' => 'web']);
    $editRole->givePermissionTo(PermissionEnum::CanEditUsers->value);

    $groupA = UserGroup::factory()->create(['name' => 'Group A']);
    $groupA->roles()->sync([$showRole->id]);

    $groupB = UserGroup::factory()->create(['name' => 'Group B']);
    $groupB->roles()->sync([$editRole->id]);

    $user = User::factory()->create();
    $user->groups()->sync([$groupA->id, $groupB->id]);

    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->hasPermission($user, PermissionEnum::CanShowGroups->value))->toBeTrue();
    expect($resolver->hasPermission($user, PermissionEnum::CanEditUsers->value))->toBeTrue();
    expect($resolver->hasPermission($user, PermissionEnum::CanDeleteGroups->value))->toBeFalse();
});

it('returns json for group multi-select pickers', function (): void {
    $user = User::factory()->create();
    grantGroupPermissions($user, [PermissionEnum::CanShowGroups]);
    $this->actingAs($user);

    $group = UserGroup::factory()->create(['name' => 'Picker Group']);

    $this->getJson(route('admin.groups.index', ['search' => 'Picker Group', 'per_page' => 20]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $group->id)
        ->assertJsonPath('data.0.name', 'Picker Group');
});

it('refuses attaching the super-admin role unless the actor is a super-admin', function (): void {
    syncPermissions();

    $actor = User::factory()->create();
    grantGroupPermissions($actor, [
        PermissionEnum::CanShowGroups,
        PermissionEnum::CanCreateGroups,
    ]);
    $this->actingAs($actor);

    $superAdminRole = Role::findByName(RoleEnum::SuperAdmin->value);

    $this->post(route('admin.groups.store'), [
        'name' => 'Owners',
        'role_ids' => [$superAdminRole->id],
    ])->assertSessionHasErrors('role_ids');

    expect(UserGroup::query()->where('name', 'Owners')->exists())->toBeFalse();
});
