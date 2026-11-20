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
        ->patchJson('/api/v1/users/'.$superAdmin->id, [
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

it('blocks admin writes while impersonating', function (): void {
    $admin = permissionAdmin();
    $target = User::factory()->create();
    $other = User::factory()->create(['email' => 'other@example.com']);

    $this->actingAs($admin)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertRedirect(route('dashboard'));

    $this->put(route('admin.users.update', $other), [
        'first_name' => 'Changed',
        'last_name' => 'While',
        'email' => 'other@example.com',
        'password' => '',
    ])->assertForbidden();

    expect($other->fresh()->first_name)->not->toBe('Changed');
});

/**
 * @param  list<string>  $scopes
 * @return array{plain: string, apiKey: ApiKey, admin: User}
 */
function adminApiKeyFor(User $owner, array $scopes): array
{
    $issued = app(CreateApiKeyAction::class)->execute(
        $owner,
        new ApiKeyData('Security review key', $scopes),
    );

    return [
        'admin' => $owner,
        'plain' => $issued->plainTextToken,
        'apiKey' => $issued->apiKey,
    ];
}
