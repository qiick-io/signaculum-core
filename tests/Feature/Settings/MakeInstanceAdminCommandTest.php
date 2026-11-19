<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;

beforeEach(function (): void {
    syncPermissions();
});

test('the command grants and revokes instance admin rights', function () {
    $keeper = User::factory()->create(['email' => 'keeper@example.test']);
    $keeper->assignRole(RoleEnum::SuperAdmin->value);

    $user = User::factory()->create(['email' => 'ops@example.test']);

    $this->artisan('signaculum:make-admin', ['email' => 'ops@example.test'])->assertSuccessful();
    expect($user->refresh()->isSuperAdmin())->toBeTrue();

    $this->artisan('signaculum:make-admin', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->isSuperAdmin())->toBeFalse();
});

test('the command refuses to revoke the last super-admin', function () {
    $user = User::factory()->create(['email' => 'only@example.test']);
    $user->assignRole(RoleEnum::SuperAdmin->value);

    $this->artisan('signaculum:make-admin', ['email' => 'only@example.test', '--revoke' => true])->assertFailed();
    expect($user->refresh()->isSuperAdmin())->toBeTrue();
});

test('the command fails for an unknown email', function () {
    $this->artisan('signaculum:make-admin', ['email' => 'nobody@example.test'])->assertFailed();
});

test('only instance admins see the instance settings group', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageSettings', false));

    $this->actingAs(User::factory()->instanceAdmin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.canManageSettings', true));
});
