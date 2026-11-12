<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Admin users list and create/edit (#21). Activation toggles are out of scope here.
 */
function instanceAdminUser(array $attributes = []): User
{
    return User::factory()->instanceAdmin()->create($attributes);
}

/**
 * @return array<string, mixed>
 */
function userFormPayload(array $overrides = []): array
{
    return [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
        ...$overrides,
    ];
}

test('a non admin cannot open the users list or create users', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.users.store'), userFormPayload())->assertForbidden();
    $this->actingAs($user)->put(route('admin.users.update', $other), userFormPayload([
        'email' => $other->email,
        'password' => '',
    ]))->assertForbidden();
});

test('an instance admin lists users with the inertia page', function () {
    $admin = instanceAdminUser(['first_name' => 'Ada', 'last_name' => 'Admin']);
    User::factory()->create(['first_name' => 'Bob', 'last_name' => 'User']);

    $this->actingAs($admin)->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/users/index')
            ->has('users.data', 2)
            ->where('filters.status', 'active'));
});

test('the users list searches sorts filters and paginates', function () {
    $admin = instanceAdminUser(['first_name' => 'Zed', 'last_name' => 'Admin', 'email' => 'zed@example.com']);
    User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com']);
    User::factory()->create(['first_name' => 'Alan', 'last_name' => 'Turing', 'email' => 'alan@example.com']);
    User::factory()->inactive()->create(['first_name' => 'Inactive', 'last_name' => 'Person', 'email' => 'off@example.com']);

    $this->actingAs($admin)->get(route('admin.users.index', [
        'search' => 'ada@',
        'sort' => 'name',
        'direction' => 'asc',
        'status' => 'active',
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('users.data', 1)
        ->where('users.data.0.name', 'Ada Lovelace')
        ->where('filters.search', 'ada@')
        ->where('filters.sort', 'name')
        ->where('filters.direction', 'asc')
        ->where('filters.status', 'active'));

    $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'deactivated']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'off@example.com')
            ->where('filters.status', 'deactivated'));

    foreach (range(1, 8) as $index) {
        User::factory()->create([
            'first_name' => 'Zz',
            'last_name' => "User {$index}",
            'email' => "zz{$index}@example.com",
        ]);
    }

    $this->actingAs($admin)->get(route('admin.users.index', [
        'sort' => 'name',
        'direction' => 'asc',
        'per_page' => 10,
        'status' => 'active',
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('users.data', 10)
        ->where('users.meta.per_page', 10)
        ->where('users.meta.last_page', 2));

    $this->actingAs($admin)->get(route('admin.users.index', ['per_page' => 7, 'status' => 'active']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('users.meta.per_page', 20));
});

test('an instance admin creates a verified active user', function () {
    $admin = instanceAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), userFormPayload())
        ->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'grace@example.com')->sole();

    expect($user->first_name)->toBe('Grace')
        ->and($user->last_name)->toBe('Hopper')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and($user->is_instance_admin)->toBeFalse();
});

test('creating a user validates email uniqueness and password', function () {
    $admin = instanceAdminUser();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), userFormPayload([
            'email' => $admin->email,
            'password' => 'short',
        ]))
        ->assertSessionHasErrors(['email', 'password']);
});

test('an instance admin updates a user and keeps the password when empty', function () {
    $admin = instanceAdminUser();
    $user = User::factory()->create([
        'first_name' => 'Old',
        'last_name' => 'Name',
        'email' => 'old@example.com',
    ]);
    $password = $user->password;

    $this->actingAs($admin)
        ->put(route('admin.users.update', $user), userFormPayload([
            'email' => 'new@example.com',
            'password' => '',
        ]))
        ->assertRedirect(route('admin.users.index'));

    $user->refresh();

    expect($user->name)->toBe('Grace Hopper')
        ->and($user->email)->toBe('new@example.com')
        ->and($user->password)->toBe($password);
});

test('a guest is redirected to login from the users list', function () {
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
});
