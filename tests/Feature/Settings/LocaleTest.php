<?php

declare(strict_types=1);

use App\Models\User;

test('a user picks a ui language that is saved and mirrored in a cookie', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('locale.update'), ['locale' => 'it'])
        ->assertRedirect(route('profile.edit'))
        ->assertCookie('locale', 'it', encrypted: false);

    expect($user->refresh()->locale)->toBe('it');
});

test('only offered languages are accepted', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('locale.update'), ['locale' => 'xx'])
        ->assertSessionHasErrors('locale');
});

test('the user language drives the request locale', function () {
    $user = User::factory()->create(['locale' => 'de']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('locale', 'de'));
});

test('guests get the language of the locale cookie', function () {
    $this->withUnencryptedCookie('locale', 'it')
        ->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('locale', 'it'));
});
