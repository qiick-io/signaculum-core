<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    syncPermissions();

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole(RoleEnum::SuperAdmin->value);

    $this->group = UserGroup::query()->create(['name' => 'Editors']);
    $this->superAdminGroup = UserGroup::query()->create(['name' => 'Owners']);
    $this->superAdminGroup->roles()->sync([Role::findByName(RoleEnum::SuperAdmin->value)->id]);

    $this->manager = User::factory()->create();
    $managerRole = Role::query()->firstOrCreate([
        'name' => 'group-manager',
        'guard_name' => 'web',
    ]);

    foreach ([
        PermissionEnum::CanShowGroups,
        PermissionEnum::CanCreateGroups,
        PermissionEnum::CanEditGroups,
        PermissionEnum::CanDeleteGroups,
        PermissionEnum::CanRestoreGroups,
        PermissionEnum::CanForceDeleteGroups,
    ] as $permission) {
        Permission::findOrCreate($permission->value, 'web');
    }

    $managerRole->syncPermissions([
        PermissionEnum::CanShowGroups->value,
        PermissionEnum::CanCreateGroups->value,
        PermissionEnum::CanEditGroups->value,
        PermissionEnum::CanDeleteGroups->value,
        PermissionEnum::CanRestoreGroups->value,
        PermissionEnum::CanForceDeleteGroups->value,
    ]);
    $this->manager->assignRole($managerRole);

    $this->outsider = User::factory()->create();
});

it('allows class abilities for the matching permission', function (string $ability): void {
    expect(Gate::forUser($this->manager)->allows($ability, UserGroup::class))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows($ability, UserGroup::class))->toBeFalse()
        ->and(Gate::forUser($this->superAdmin)->allows($ability, UserGroup::class))->toBeTrue();
})->with(['viewAny', 'create']);

it('allows record abilities for the matching permission', function (string $ability): void {
    expect(Gate::forUser($this->manager)->allows($ability, $this->group))->toBeTrue()
        ->and(Gate::forUser($this->outsider)->allows($ability, $this->group))->toBeFalse()
        ->and(Gate::forUser($this->superAdmin)->allows($ability, $this->group))->toBeTrue();
})->with(['update', 'delete', 'restore', 'forceDelete']);

it('allows only a super-admin to touch a group that grants super-admin', function (string $ability): void {
    expect(Gate::forUser($this->manager)->allows($ability, $this->superAdminGroup))->toBeFalse()
        ->and(Gate::forUser($this->superAdmin)->allows($ability, $this->superAdminGroup))->toBeTrue();
})->with(['update', 'delete', 'restore', 'forceDelete']);
