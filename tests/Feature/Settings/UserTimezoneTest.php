<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Fleet core schema: users.timezone is IANA, default UTC, editable on the
 * account profile form and the Admin API (#192).
 */
test('users table has a non-null timezone column defaulting to UTC', function (): void {
    expect(Schema::hasColumn('users', 'timezone'))->toBeTrue();

    $user = User::factory()->create();

    expect($user->timezone)->toBe('UTC');
});

test('profile page exposes the IANA timezone list and the current timezone', function (): void {
    $user = User::factory()->create(['timezone' => 'Europe/Rome']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->has('timezones')
            ->where('timezones', timezone_identifiers_list())
            ->where('auth.user.timezone', 'Europe/Rome'));
});

test('profile update persists a valid IANA timezone and locale', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'locale' => 'it',
            'timezone' => 'Europe/Rome',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'))
        ->assertCookie('locale', 'it', encrypted: false);

    $user->refresh();

    expect($user->timezone)->toBe('Europe/Rome')
        ->and($user->locale)->toBe('it');
});

test('profile update rejects a non-IANA timezone', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $user->email,
            'locale' => 'en',
            'timezone' => 'Not/A_Zone',
        ])
        ->assertSessionHasErrors('timezone')
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->timezone)->toBe('UTC');
});

test('admin api update persists and rejects timezone like the profile form', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create();

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, [
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'timezone' => 'America/New_York',
        ])
        ->assertOk()
        ->assertJsonPath('data.timezone', 'America/New_York');

    expect($user->refresh()->timezone)->toBe('America/New_York');

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, [
            'first_name' => $user->first_name,
            'email' => $user->email,
            'timezone' => 'Not/A_Zone',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['timezone']);
});
