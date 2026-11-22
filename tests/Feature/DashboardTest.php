<?php

declare(strict_types=1);

use App\Models\User;

test('guests are redirected to the login page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('home shows the key numbers of the account', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('activeSessionCount', 1)
            ->where('hasTwoFactor', false)
            ->where('passkeyCount', 0)
            ->has('connectedApplications', 0));
});
