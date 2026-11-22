<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\Authorization\SuperAdminGuardService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Security review #208: delegated admins cannot promote themselves or mutate super-admins.
 */
beforeEach(function (): void {
    syncPermissions();
});

it('refuses an admin promoting themselves to super-admin via instance-admin', function (): void {
    $admin = permissionAdmin();

    $this->actingAs($admin)
        ->put(route('admin.users.instance-admin.update', $admin), [
            'is_instance_admin' => true,
        ])
        ->assertForbidden();

    expect(app(EffectivePermissionResolverService::class)->isSuperAdmin($admin->fresh()))->toBeFalse();
});

it('refuses an admin editing a super-admin password email activation two-factor or unlock', function (): void {
    $admin = permissionAdmin();
    $superAdmin = User::factory()->instanceAdmin()->create([
        'email' => 'super-target@example.com',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code'])),
        'two_factor_confirmed_at' => now(),
        'locked_until' => now()->addHour(),
        'failed_login_attempts' => 5,
    ]);

    $this->actingAs($admin)
        ->put(route('admin.users.update', $superAdmin), [
            'first_name' => 'Hacked',
            'last_name' => 'Name',
            'email' => 'hacked@example.com',
            'password' => 'Brand-new-password-9',
        ])
        ->assertForbidden();

    $this->actingAs($admin)
        ->put(route('admin.users.activation.update', $superAdmin), ['is_active' => false])
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('admin.users.two-factor.destroy', $superAdmin))
        ->assertForbidden();

    $this->actingAs($admin)
        ->delete(route('admin.users.lockout.destroy', $superAdmin))
        ->assertForbidden();

    $superAdmin->refresh();

    expect($superAdmin->email)->toBe('super-target@example.com')
        ->and($superAdmin->is_active)->toBeTrue()
        ->and($superAdmin->two_factor_confirmed_at)->not->toBeNull()
        ->and($superAdmin->locked_until)->not->toBeNull();
});

it('refuses an admin patching a super-admin through the API', function (): void {
    $admin = permissionAdmin();
    $superAdmin = User::factory()->instanceAdmin()->create(['email' => 'api-super@example.com']);

    ['plain' => $plain] = adminApiKeyFor($admin, [ApiKeyScopeEnum::UsersWrite->value]);

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$superAdmin->subject, [
            'first_name' => 'Nope',
            'last_name' => 'Nope',
            'email' => 'nope@example.com',
            'password' => 'Brand-new-password-9',
        ])
        ->assertForbidden();

    expect($superAdmin->fresh()->email)->toBe('api-super@example.com');
});

it('stops granting group permissions after the group is soft-deleted', function (): void {
    $user = User::factory()->create();
    $group = UserGroup::factory()->create(['name' => 'Temp ops']);
    $adminRole = Role::findByName(RoleEnum::Admin->value);

    $group->roles()->attach($adminRole->id);
    $group->users()->attach($user->id);

    $resolver = app(EffectivePermissionResolverService::class);

    expect($resolver->hasPermission($user, PermissionEnum::CanEditUsers->value))->toBeTrue();

    $group->delete();
    $resolver->forget($user);

    expect($resolver->hasPermission($user->fresh(), PermissionEnum::CanEditUsers->value))->toBeFalse();
});

it('refuses impersonating a target whose permissions are not a subset of the actor', function (): void {
    $impersonatorRole = Role::create(['name' => 'impersonator-only', 'guard_name' => 'web']);
    $impersonatorRole->givePermissionTo(PermissionEnum::CanImpersonateUsers->value);

    $extraPermission = Permission::findOrCreate(PermissionEnum::CanEditUsers->value, 'web');

    $actor = User::factory()->create();
    $actor->assignRole($impersonatorRole);

    $target = User::factory()->create();
    $target->givePermissionTo($extraPermission);

    $this->actingAs($actor)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertForbidden();
});

it('blocks admin writes while impersonating a target who can edit users but holds no built-in role', function (): void {
    $admin = permissionAdmin();
    $target = User::factory()->create();
    $target->givePermissionTo(PermissionEnum::CanEditUsers->value);
    $other = User::factory()->create(['email' => 'other@example.com', 'first_name' => 'Untouched']);

    $this->actingAs($admin)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);

    $this->put(route('admin.users.update', $other), [
        'first_name' => 'Changed',
        'last_name' => 'While',
        'email' => 'other@example.com',
        'password' => '',
    ])->assertForbidden();

    expect($other->fresh()->first_name)->toBe('Untouched');
});

it('refuses impersonating an admin-role target', function (): void {
    $actor = permissionAdmin();
    $target = permissionAdmin();

    $this->actingAs($actor)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertForbidden();
});

it('refuses deactivating the last active super-admin when the only other is deactivated', function (): void {
    $active = User::factory()->instanceAdmin()->create(['email' => 'active-sa@example.com']);
    $inactive = User::factory()->instanceAdmin()->create([
        'email' => 'inactive-sa@example.com',
        'is_active' => false,
    ]);

    $this->actingAs($active)
        ->put(route('admin.users.activation.update', $active), ['is_active' => false])
        ->assertSessionHasErrors('user');

    expect($active->fresh()->is_active)->toBeTrue()
        ->and($inactive->fresh()->is_active)->toBeFalse();
});

it('refuses deleting the last active super-admin when the only other is deactivated', function (): void {
    $active = User::factory()->instanceAdmin()->create([
        'email' => 'delete-sa@example.com',
        'password' => 'password',
    ]);
    User::factory()->instanceAdmin()->create([
        'email' => 'other-inactive-sa@example.com',
        'is_active' => false,
    ]);

    $this->actingAs($active)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($active->fresh())->not->toBeNull()
        ->and($active->fresh()->is_active)->toBeTrue();
});

it('refuses deactivating and deleting a user who is super-admin only through a group', function (): void {
    $groupOnly = User::factory()->create([
        'email' => 'group-sa@example.com',
        'password' => 'password',
    ]);
    $group = UserGroup::factory()->create(['name' => 'Sole owners']);
    $group->roles()->attach(Role::findByName(RoleEnum::SuperAdmin->value)->id);
    $group->users()->attach($groupOnly->id);
    app(EffectivePermissionResolverService::class)->forget();

    expect(app(EffectivePermissionResolverService::class)->isSuperAdmin($groupOnly))->toBeTrue()
        ->and(app(SuperAdminGuardService::class)->countActiveSuperAdmins())->toBe(1);

    $this->actingAs($groupOnly)
        ->put(route('admin.users.activation.update', $groupOnly), ['is_active' => false])
        ->assertSessionHasErrors('user');

    expect($groupOnly->fresh()->is_active)->toBeTrue();

    $this->actingAs($groupOnly)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertSessionHasErrors('password');

    expect($groupOnly->fresh())->not->toBeNull();
});

it('refuses deleting the only super-admin group and stripping its role', function (): void {
    $member = User::factory()->create(['email' => 'delete-group-sa@example.com']);
    $group = UserGroup::factory()->create(['name' => 'Only owners']);
    $superAdminRole = Role::findByName(RoleEnum::SuperAdmin->value);
    $adminRole = Role::findByName(RoleEnum::Admin->value);

    $group->roles()->attach($superAdminRole->id);
    $group->users()->attach($member->id);
    app(EffectivePermissionResolverService::class)->forget();

    $this->actingAs($member)
        ->delete(route('admin.groups.destroy', $group))
        ->assertSessionHasErrors('group');

    expect($group->fresh()->trashed())->toBeFalse();

    $this->actingAs($member)
        ->put(route('admin.groups.update', $group), [
            'name' => 'Only owners',
            'description' => null,
            'user_ids' => [$member->id],
            'role_ids' => [$adminRole->id],
        ])
        ->assertSessionHasErrors('role_ids');

    expect($group->fresh()->roles()->pluck('roles.id')->all())->toContain($superAdminRole->id);
});

it('bulk restores soft-deleted groups and force-deletes when safe', function (): void {
    $actor = User::factory()->instanceAdmin()->create();
    $safe = UserGroup::factory()->create(['name' => 'Safe trash']);
    $safe->delete();

    $this->actingAs($actor)
        ->post(route('admin.groups.bulk'), [
            'action' => 'restore',
            'ids' => [$safe->id],
        ])
        ->assertRedirect(route('admin.groups.index', ['trashed' => 1]));

    expect($safe->fresh()->trashed())->toBeFalse();

    $safe->delete();

    $this->actingAs($actor)
        ->post(route('admin.groups.bulk'), [
            'action' => 'force_delete',
            'ids' => [$safe->id],
        ])
        ->assertRedirect(route('admin.groups.index', ['trashed' => 1]));

    expect(UserGroup::withTrashed()->find($safe->id))->toBeNull();
});

it('refuses emptying the only super-admin group members and writes nothing', function (): void {
    $member = User::factory()->create(['email' => 'sole-group-member@example.com']);
    $group = UserGroup::factory()->create(['name' => 'Owners', 'description' => 'keep me']);
    $superAdminRole = Role::findByName(RoleEnum::SuperAdmin->value);

    $group->roles()->attach($superAdminRole->id);
    $group->users()->attach($member->id);
    app(EffectivePermissionResolverService::class)->forget();

    expect(app(EffectivePermissionResolverService::class)->isSuperAdmin($member))->toBeTrue()
        ->and(app(SuperAdminGuardService::class)->countActiveSuperAdmins())->toBe(1);

    // Member is super-admin via the group, so Gate::before lets them update it; emptying must still refuse.
    $this->actingAs($member)
        ->put(route('admin.groups.update', $group), [
            'name' => 'Owners renamed',
            'description' => 'changed',
            'user_ids' => [],
        ])
        ->assertSessionHasErrors('user_ids');

    $group->refresh();

    expect($group->name)->toBe('Owners')
        ->and($group->description)->toBe('keep me')
        ->and($group->users()->pluck('users.id')->all())->toBe([$member->id])
        ->and(app(EffectivePermissionResolverService::class)->isSuperAdmin($member->fresh()))->toBeTrue();
});

/**
 * @param  list<string>  $scopes
 * @return array{plain: string, apiKey: ApiKey, admin: User}
 */
function adminApiKeyFor(User $owner, array $scopes): array
{
    $issued = app(CreateApiKeyAction::class)->execute(
        $owner,
        new ApiKeyData('Security review key', $scopes, expiresAt: apiKeyExpiry()),
    );

    return [
        'admin' => $owner,
        'plain' => $issued->plainTextToken,
        'apiKey' => $issued->apiKey,
    ];
}
