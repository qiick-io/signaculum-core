<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use App\Models\User;

/**
 * Reader and admin-role actors get 403 on mutating admin routes they must not use (#208).
 * Admin role may manage ordinary users but not promote to super-admin.
 */
beforeEach(function (): void {
    syncPermissions();
});

dataset('admin_mutating_routes_for_reader', function () {
    return [
        'users store' => [
            fn () => ['method' => 'post', 'route' => 'admin.users.store', 'params' => [], 'payload' => [
                'first_name' => 'X',
                'last_name' => 'Y',
                'email' => 'reader-create@example.com',
                'password' => 'correct-horse-battery',
            ]],
        ],
        'users update' => [
            fn () => ['method' => 'put', 'route' => 'admin.users.update', 'params' => [User::factory()->create()], 'payload' => [
                'first_name' => 'X',
                'last_name' => 'Y',
                'email' => 'reader-update@example.com',
                'password' => '',
            ]],
        ],
        'users activation' => [
            fn () => ['method' => 'put', 'route' => 'admin.users.activation.update', 'params' => [User::factory()->create()], 'payload' => [
                'is_active' => false,
            ]],
        ],
        'instance admin' => [
            fn () => ['method' => 'put', 'route' => 'admin.users.instance-admin.update', 'params' => [User::factory()->create()], 'payload' => [
                'is_instance_admin' => true,
            ]],
        ],
        'applications store' => [
            fn () => ['method' => 'post', 'route' => 'admin.applications.store', 'params' => [], 'payload' => [
                'name' => 'Blocked App',
                'redirect_uris' => 'https://example.com/callback',
                'is_confidential' => true,
                'is_first_party' => false,
                'is_client_credentials_enabled' => false,
                'is_device_authorization_enabled' => false,
                'is_pushed_authorization_required' => false,
            ]],
        ],
        'groups store' => [
            fn () => ['method' => 'post', 'route' => 'admin.groups.store', 'params' => [], 'payload' => [
                'name' => 'Blocked Group',
                'description' => null,
                'role_ids' => [],
                'user_ids' => [],
            ]],
        ],
    ];
});

it('refuses reader mutations on admin routes', function (callable $caseFactory): void {
    $reader = permissionReader();
    $case = $caseFactory();

    $this->actingAs($reader)
        ->{$case['method']}(route($case['route'], $case['params']), $case['payload'])
        ->assertForbidden();
})->with('admin_mutating_routes_for_reader');

it('refuses an admin granting super-admin while allowing ordinary user edits', function (): void {
    $admin = permissionAdmin();
    $target = User::factory()->create(['email' => 'ordinary@example.com']);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $target), [
            'first_name' => 'Ordinary',
            'last_name' => 'Edited',
            'email' => 'ordinary@example.com',
            'password' => '',
        ])
        ->assertRedirect();

    expect($target->fresh()->first_name)->toBe('Ordinary');

    $this->actingAs($admin)
        ->put(route('admin.users.instance-admin.update', $target), [
            'is_instance_admin' => true,
        ])
        ->assertForbidden();

    expect($target->fresh()->hasRole(RoleEnum::SuperAdmin->value))->toBeFalse();
});
