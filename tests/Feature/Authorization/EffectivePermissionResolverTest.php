<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use Qiick\Access\EffectivePermissionResolver;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    syncPermissions();
});

it('grants a permission through a group role', function (): void {
    $user = User::factory()->create();
    $group = UserGroup::factory()->create(['name' => 'Operators']);
    $adminRole = Role::findByName(RoleEnum::Admin->value);

    $group->roles()->attach($adminRole->id);
    $group->users()->attach($user->id);

    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->hasPermission($user, PermissionEnum::CanShowUsers->value))->toBeTrue()
        ->and($resolver->roleNamesFor($user))->toContain(RoleEnum::Admin->value);
});

it('denies a permission when the user has neither direct nor group roles', function (): void {
    $user = User::factory()->create();
    $resolver = app(EffectivePermissionResolver::class);

    expect($resolver->hasPermission($user, PermissionEnum::CanShowUsers->value))->toBeFalse();
});
