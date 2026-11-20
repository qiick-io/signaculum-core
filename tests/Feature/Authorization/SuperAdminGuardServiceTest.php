<?php

declare(strict_types=1);

use App\Actions\SetInstanceAdminAction;
use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\SuperAdminGuardService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    syncPermissions();
});

it('reports super-admin role names and users through groups', function (): void {
    $guard = app(SuperAdminGuardService::class);
    $superAdminRole = Role::findByName(RoleEnum::SuperAdmin->value);
    $adminRole = Role::findByName(RoleEnum::Admin->value);

    expect($guard->hasSuperAdminRoleName([RoleEnum::Admin->value]))->toBeFalse()
        ->and($guard->hasSuperAdminRoleName([RoleEnum::SuperAdmin->value]))->toBeTrue()
        ->and($guard->hasSuperAdminRole([$adminRole->id]))->toBeFalse()
        ->and($guard->hasSuperAdminRole([$superAdminRole->id]))->toBeTrue();

    $direct = User::factory()->instanceAdmin()->create();
    $viaGroup = User::factory()->create();
    $ordinary = User::factory()->create();
    $group = UserGroup::factory()->create();
    $group->roles()->attach($superAdminRole->id);
    $group->users()->attach($viaGroup->id);

    expect($guard->hasSuperAdminUser([$ordinary->id]))->toBeFalse()
        ->and($guard->hasSuperAdminUser([$direct->id]))->toBeTrue()
        ->and($guard->hasSuperAdminUser([$viaGroup->id]))->toBeTrue()
        ->and($guard->hasSuperAdminGroup([$group->id]))->toBeTrue()
        ->and($guard->hasSuperAdminGroup([]))->toBeFalse();
});

it('detects when removing a group would drop the last active super-admin path', function (): void {
    $guard = app(SuperAdminGuardService::class);
    $superAdminRole = Role::findByName(RoleEnum::SuperAdmin->value);

    $member = User::factory()->create();
    $group = UserGroup::factory()->create();
    $group->roles()->attach($superAdminRole->id);
    $group->users()->attach($member->id);
    app(EffectivePermissionResolverService::class)->forget();

    expect($guard->wouldRemoveLastActiveSuperAdminViaGroups([]))->toBeFalse()
        ->and($guard->wouldRemoveLastActiveSuperAdminViaGroups([$group->id]))->toBeTrue();

    $second = User::factory()->instanceAdmin()->create();
    app(EffectivePermissionResolverService::class)->forget();

    expect($guard->wouldRemoveLastActiveSuperAdminViaGroups([$group->id]))->toBeFalse()
        ->and($guard->countActiveSuperAdmins())->toBe(2)
        ->and($guard->isLastActiveSuperAdmin($second))->toBeFalse()
        ->and($guard->isLastActiveSuperAdmin($member))->toBeFalse();
});

it('allows a no-op group update when there are no active super-admins', function (): void {
    $guard = app(SuperAdminGuardService::class);
    $group = UserGroup::factory()->create();

    expect($guard->countActiveSuperAdmins())->toBe(0)
        ->and($guard->wouldLeaveNoActiveSuperAdminAfterGroupUpdate($group, [], null))->toBeFalse();
});

it('keeps a remaining path when swapping members of a super-admin group', function (): void {
    $guard = app(SuperAdminGuardService::class);
    $superAdminRole = Role::findByName(RoleEnum::SuperAdmin->value);

    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $group = UserGroup::factory()->create();
    $group->roles()->attach($superAdminRole->id);
    $group->users()->attach($outgoing->id);
    app(EffectivePermissionResolverService::class)->forget();

    expect($guard->wouldLeaveNoActiveSuperAdminAfterGroupUpdate(
        $group,
        [$incoming->id],
        [$superAdminRole->id],
    ))->toBeFalse();
});

it('refuses SetInstanceAdminAction for a non-super-admin actor and the last revoke', function (): void {
    $action = app(SetInstanceAdminAction::class);
    $admin = permissionAdmin();
    $target = User::factory()->create();

    expect(fn () => $action->execute($target, true, $admin))
        ->toThrow(AuthorizationException::class);

    $sole = User::factory()->instanceAdmin()->create();

    expect(fn () => $action->execute($sole, false, $sole))
        ->toThrow(ValidationException::class);

    $keeper = User::factory()->instanceAdmin()->create();
    $action->execute($sole, false, $keeper);
    app(EffectivePermissionResolverService::class)->forget();

    expect($sole->fresh()->isSuperAdmin())->toBeFalse()
        ->and($keeper->fresh()->isSuperAdmin())->toBeTrue();

    $action->execute($sole, true, $keeper);
    expect($sole->fresh()->isSuperAdmin())->toBeTrue();
});
