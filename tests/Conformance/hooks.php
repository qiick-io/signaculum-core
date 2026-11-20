<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use App\Enums\RoleEnum;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\UserGroup;
use App\Services\Authorization\EffectivePermissionResolverService;
use App\Services\WebhookUrlGuardService;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Product-owned behaviour hooks for the fleet conformance suite.
 *
 * @return array<string, callable(): void>
 */
return [
    'assertSoftDeletedGroupGrantsNothing' => static function (): void {
        syncPermissions();

        $user = User::factory()->create();
        $group = UserGroup::query()->create([
            'name' => 'conformance-deleted-group-'.uniqid(),
            'description' => null,
        ]);
        $readerRole = Role::findByName(RoleEnum::Reader->value, 'web');
        $group->roles()->attach($readerRole->getKey());
        $group->users()->attach($user->getKey());

        $resolver = app(EffectivePermissionResolverService::class);
        $permission = PermissionEnum::CanShowUsers->value;

        if (! $resolver->hasPermission($user, $permission)) {
            throw new RuntimeException('Expected the reader group role to grant can-show-users before soft-delete.');
        }

        $group->delete();
        $resolver->forget($user);
        $user->unsetRelation('groups');
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        if ($resolver->hasPermission($user->fresh(), $permission)) {
            throw new RuntimeException('Soft-deleted group still grants permissions through the resolver.');
        }
    },

    'assertGlobalRoleGuard' => static function (): void {
        syncPermissions();

        $roleTable = config('permission.table_names.roles', 'roles');
        $guard = 'web';
        $name = RoleEnum::Admin->value;

        if (DB::table($roleTable)->where('name', $name)->where('guard_name', $guard)->count() !== 1) {
            throw new RuntimeException("Expected exactly one {$name} role before the duplicate probe.");
        }

        test()->artisan('signaculum:sync-permissions')->assertSuccessful();

        if (DB::table($roleTable)->where('name', $name)->where('guard_name', $guard)->count() !== 1) {
            throw new RuntimeException('Global role definitions must not duplicate via sync-permissions.');
        }
    },

    'assertSsrfGuard' => static function (): void {
        $guard = app(WebhookUrlGuardService::class);

        $metadata = $guard->authorizeOutbound('http://169.254.169.254/latest/meta-data/');

        if (($metadata['ok'] ?? true) === true) {
            throw new RuntimeException('SSRF guard must refuse the EC2 metadata address.');
        }

        $decimal = $guard->authorizeOutbound('http://2852039166/');

        if (($decimal['ok'] ?? true) === true) {
            throw new RuntimeException('SSRF guard must refuse non-dotted-decimal IPv4 spellings of metadata.');
        }

        $safe = $guard->authorizeOutbound('https://example.com/hooks');

        if (($safe['ok'] ?? false) !== true) {
            throw new RuntimeException('SSRF guard must allow a public HTTPS URL: '.($safe['reason'] ?? 'unknown'));
        }

        if (! isset($safe['curl_connect_to']) || $safe['curl_connect_to'] === []) {
            throw new RuntimeException('SSRF guard must pin with CURLOPT_CONNECT_TO entries.');
        }
    },

    'assertSuperAdmin' => static function (): void {
        syncPermissions();

        $superAdmin = User::factory()->instanceAdmin()->create();
        $resolver = app(EffectivePermissionResolverService::class);

        if (! $resolver->isSuperAdmin($superAdmin)) {
            throw new RuntimeException('Instance super-admin must resolve with zero organisations.');
        }

        if (OrganizationMembership::query()->where('user_id', $superAdmin->getKey())->exists()) {
            throw new RuntimeException('Conformance super-admin fixture must have no organisations.');
        }
    },
];
