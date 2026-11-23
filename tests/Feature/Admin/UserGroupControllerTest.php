<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Admin → Groups field by field: the list (sort, search, trash, JSON), and what a create, edit
 * or deletion stores, confirms and makes effective at once for the members' permissions.
 */
beforeEach(function (): void {
    $this->freezeSecond();
    $this->admin = User::factory()->instanceAdmin()->create(['first_name' => 'Zed', 'last_name' => 'Admin']);
    $this->adminRole = Role::query()->where('name', RoleEnum::Admin->value)->sole();
});

function groupNamesOnPage(array $query): array
{
    $names = [];

    test()->actingAs(test()->admin)->get(route('admin.groups.index', $query))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$names): void {
            $names = collect($page->toArray()['props']['groups']['data'])->pluck('name')->all();
        });

    return $names;
}

it('sorts by an allowed column in either direction, and by name otherwise', function (): void {
    UserGroup::factory()->create(['name' => 'Bravo']);
    $this->travel(1)->minute();
    UserGroup::factory()->create(['name' => 'Alpha']);
    $this->travel(1)->minute();
    UserGroup::factory()->create(['name' => 'Charlie']);

    expect(groupNamesOnPage([]))->toBe(['Alpha', 'Bravo', 'Charlie'])
        ->and(groupNamesOnPage(['sort' => 'name', 'direction' => 'DESC']))->toBe(['Charlie', 'Bravo', 'Alpha'])
        ->and(groupNamesOnPage(['sort' => 'created_at']))->toBe(['Bravo', 'Alpha', 'Charlie'])
        ->and(groupNamesOnPage(['sort' => 'updated_at', 'direction' => 'desc']))->toBe(['Charlie', 'Alpha', 'Bravo'])
        ->and(groupNamesOnPage(['sort' => 'password', 'direction' => 'sideways']))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('searches names and descriptions, and lists the trash on its own', function (): void {
    UserGroup::factory()->create(['name' => 'Night shift', 'description' => null]);
    UserGroup::factory()->create(['name' => 'Ops', 'description' => 'Runs the night deploys']);
    UserGroup::factory()->create(['name' => 'Sales', 'description' => 'Daytime']);
    UserGroup::factory()->create(['name' => 'Archived night', 'description' => null])->delete();

    expect(groupNamesOnPage(['search' => 'night']))->toBe(['Night shift', 'Ops'])
        ->and(groupNamesOnPage(['trashed' => 1]))->toBe(['Archived night'])
        ->and(groupNamesOnPage([]))->toBe(['Night shift', 'Ops', 'Sales']);
});

it('gives the page each group with its members, roles and counts, and the choices for the form', function (): void {
    $member = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Member', 'email' => 'ada@example.test']);
    User::factory()->inactive()->create(['first_name' => 'Gone']);
    $group = UserGroup::factory()->create(['name' => 'Operators', 'description' => 'On call']);
    $group->users()->attach($member);
    $group->roles()->attach($this->adminRole);

    $this->actingAs($this->admin)->get(route('admin.groups.index', ['search' => 'Operators', 'sort' => 'name', 'direction' => 'desc', 'trashed' => 0]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['search' => 'Operators', 'trashed' => false, 'sort' => 'name', 'direction' => 'desc'])
            ->where('groups.data.0.users_count', 1)
            ->where('groups.data.0.roles_count', 1)
            ->where('groups.data.0.users', [['id' => $member->id, 'name' => 'Ada Member', 'email' => 'ada@example.test']])
            ->where('groups.data.0.roles', [['id' => $this->adminRole->id, 'name' => 'admin']])
            ->where('roles', Role::query()->where('is_assignable', true)->orderBy('name')->get(['id', 'name'])->toArray())
            ->where('users', [
                ['id' => $member->id, 'name' => 'Ada Member', 'email' => 'ada@example.test'],
                ['id' => $this->admin->id, 'name' => 'Zed Admin', 'email' => $this->admin->email],
            ]));
});

it('answers JSON with counts and without loading members', function (): void {
    $group = UserGroup::factory()->create(['name' => 'Operators']);
    $group->users()->attach(User::factory()->create());

    $this->actingAs($this->admin)->getJson(route('admin.groups.index'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Operators')
        ->assertJsonPath('data.0.users_count', 1)
        ->assertJsonPath('data.0.users', []);
});

it('creates a group with its members and roles, effective at once', function (): void {
    $member = User::factory()->create();
    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->hasPermission($member, PermissionEnum::CanManageApiKeys->value))->toBeFalse();

    $this->actingAs($this->admin)
        ->post(route('admin.groups.store'), [
            'name' => 'Operators',
            'user_ids' => ['first' => (string) $member->id],
            'role_ids' => [(string) $this->adminRole->id],
        ])
        ->assertRedirect(route('admin.groups.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Group created.']);

    $group = UserGroup::query()->where('name', 'Operators')->sole();

    expect($group->description)->toBeNull()
        ->and($group->users()->pluck('users.id')->all())->toBe([$member->id])
        ->and($group->roles()->pluck('roles.id')->all())->toBe([$this->adminRole->id])
        ->and($resolver->hasPermission($member, PermissionEnum::CanManageApiKeys->value))->toBeTrue();
});

it('creates an empty group when no members or roles are given', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.groups.store'), ['name' => 'Empty', 'description' => 'Nobody yet'])
        ->assertRedirect(route('admin.groups.index'));

    $group = UserGroup::query()->where('name', 'Empty')->sole();

    expect($group->description)->toBe('Nobody yet')
        ->and($group->users()->count())->toBe(0)
        ->and($group->roles()->count())->toBe(0);
});

it('edits a group, keeping what the edit leaves out and clearing what it empties, effective at once', function (): void {
    $member = User::factory()->create();
    $group = UserGroup::factory()->create(['name' => 'Operators', 'description' => 'Before']);
    $group->users()->attach($member);
    $group->roles()->attach($this->adminRole);
    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->hasPermission($member, PermissionEnum::CanManageApiKeys->value))->toBeTrue();

    $this->actingAs($this->admin)
        ->put(route('admin.groups.update', $group), ['name' => 'Renamed'])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Group updated.']);

    expect($group->fresh()?->description)->toBeNull()
        ->and($group->users()->pluck('users.id')->all())->toBe([$member->id])
        ->and($group->roles()->pluck('roles.id')->all())->toBe([$this->adminRole->id]);

    $this->actingAs($this->admin)
        ->put(route('admin.groups.update', $group), ['name' => 'Renamed', 'user_ids' => null, 'role_ids' => ['only' => (string) $this->adminRole->id]])
        ->assertRedirect(route('admin.groups.index'));

    expect($group->users()->count())->toBe(0)
        ->and($group->roles()->pluck('roles.id')->all())->toBe([$this->adminRole->id])
        ->and($resolver->hasPermission($member, PermissionEnum::CanManageApiKeys->value))->toBeFalse();

    $group->users()->attach($member);
    $resolver->forget();

    $this->actingAs($this->admin)
        ->put(route('admin.groups.update', $group), ['name' => 'Renamed', 'role_ids' => null, 'user_ids' => ['only' => (string) $member->id]])
        ->assertRedirect(route('admin.groups.index'));

    expect($group->roles()->count())->toBe(0)
        ->and($group->users()->pluck('users.id')->all())->toBe([$member->id]);
});

it('deletes a group, effective at once', function (): void {
    $member = User::factory()->create();
    $group = UserGroup::factory()->create();
    $group->users()->attach($member);
    $group->roles()->attach($this->adminRole);
    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->hasPermission($member, PermissionEnum::CanManageApiKeys->value))->toBeTrue();

    $this->actingAs($this->admin)
        ->delete(route('admin.groups.destroy', $group))
        ->assertRedirect(route('admin.groups.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Group deleted.']);

    expect($resolver->hasPermission($member, PermissionEnum::CanManageApiKeys->value))->toBeFalse();
});
