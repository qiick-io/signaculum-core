<?php

declare(strict_types=1);

use App\Models\User;

test('the command grants and revokes instance admin rights', function () {
    $user = User::factory()->create(['email' => 'ops@example.test']);

    $this->artisan('signaculum:make-admin', ['email' => 'ops@example.test'])->assertSuccessful();
    expect($user->refresh()->is_instance_admin)->toBeTrue();

    $this->artisan('signaculum:make-admin', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_instance_admin)->toBeFalse();
});

test('the command fails for an unknown email', function () {
    $this->artisan('signaculum:make-admin', ['email' => 'nobody@example.test'])->assertFailed();
});

test('only instance admins see the instance settings group', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageSettings', false));

    $this->actingAs(User::factory()->create(['is_instance_admin' => true]))
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageSettings', true));
});
