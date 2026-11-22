<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;

/**
 * Former `is_instance_admin` routes refuse a non-admin with 403 and admit an admin role (#206).
 */
beforeEach(function (): void {
    syncPermissions();
});

/**
 * @return list<array{0: string, 1: string}>
 */
function formerInstanceAdminGetRoutes(): array
{
    return [
        ['admin.users.index', PermissionEnum::CanShowUsers->value],
        ['admin.applications.index', PermissionEnum::CanShowApplications->value],
        ['admin.activity-logs.index', PermissionEnum::CanShowActivityLogs->value],
        ['admin.api-keys.index', PermissionEnum::CanShowApiKeys->value],
        ['admin.groups.index', PermissionEnum::CanShowGroups->value],
        ['instance.edit', PermissionEnum::CanManageSettings->value],
    ];
}

it('refuses a user without the permission on every former instance-admin GET', function (string $routeName): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route($routeName))->assertForbidden();
})->with(array_map(fn (array $pair): string => $pair[0], formerInstanceAdminGetRoutes()));

it('admits an admin role on every former instance-admin GET', function (string $routeName): void {
    $admin = User::factory()->create();
    $admin->assignRole(RoleEnum::Admin->value);

    $this->actingAs($admin)->get(route($routeName))->assertOk();
})->with(array_map(fn (array $pair): string => $pair[0], formerInstanceAdminGetRoutes()));

it('admits a super-admin on every former instance-admin GET', function (string $routeName): void {
    $this->actingAs(instanceAdmin())->get(route($routeName))->assertOk();
})->with(array_map(fn (array $pair): string => $pair[0], formerInstanceAdminGetRoutes()));
