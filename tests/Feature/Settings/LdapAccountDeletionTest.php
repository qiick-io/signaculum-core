<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Directory-linked accounts cannot self-delete (decision 2026-11-22).
 */
test('an ldap-sourced user cannot delete their account', function (): void {
    $user = User::factory()->create([
        'password' => 'password',
        'ldap_guid' => Str::lower((string) Str::uuid()),
        'ldap_dn' => 'cn=Directory User,dc=example,dc=test',
    ]);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertForbidden();

    expect($user->fresh())->not->toBeNull()
        ->and($user->fresh()?->trashed())->toBeFalse();
});

test('the profile page hides account deletion for ldap-sourced users', function (): void {
    $user = User::factory()->create([
        'ldap_guid' => Str::lower((string) Str::uuid()),
    ]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/profile')
            ->where('canDeleteAccount', false));
});

test('a local user still sees account deletion on the profile page', function (): void {
    $user = User::factory()->create(['ldap_guid' => null]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('settings/profile')
            ->where('canDeleteAccount', true));
});
