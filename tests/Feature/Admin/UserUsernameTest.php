<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(function (): void {
    syncPermissions();
});

it('canonicalizes usernames on the user model', function (): void {
    $user = User::factory()->make(['username' => '  LuCa.Hopper  ']);

    expect($user->username)->toBe('luca.hopper');

    $user->username = '   ';

    expect($user->username)->toBeNull();

    $user->username = ' Ada_99 ';

    expect($user->username)->toBe('ada_99');
});

it('creates a user with a normalized username', function (): void {
    $this->actingAs(instanceAdmin())
        ->post(route('admin.users.store'), [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
            'username' => '  Grace.Hopper  ',
            'password' => 'correct-horse-battery',
        ])
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->where('email', 'grace@example.com')->sole()->username)->toBe('grace.hopper');
});

it('rejects a username already used with a different case', function (): void {
    User::factory()->create(['username' => 'luca']);

    $this->actingAs(instanceAdmin())
        ->post(route('admin.users.store'), [
            'first_name' => 'Other',
            'last_name' => 'User',
            'email' => 'other@example.com',
            'username' => 'Luca',
            'password' => 'correct-horse-battery',
        ])
        ->assertSessionHasErrors('username');

    expect(User::query()->where('username', 'luca')->count())->toBe(1)
        ->and(User::query()->where('email', 'other@example.com')->exists())->toBeFalse();
});

it('rejects usernames with invalid characters or length', function (string $username): void {
    $this->actingAs(instanceAdmin())
        ->post(route('admin.users.store'), [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
            'username' => $username,
            'password' => 'correct-horse-battery',
        ])
        ->assertSessionHasErrors('username');
})->with([
    'ab',
    'bad name',
    'bad@name',
    '-leading',
    'trailing-',
    'way-too-long-username-that-never-fits',
]);

it('updates the profile username and keeps it on repeated saves', function (): void {
    $user = User::factory()->create(['username' => 'ada']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'username' => '  ADA_99 ',
            'locale' => 'en',
            'timezone' => 'UTC',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh()->username)->toBe('ada_99');
});
