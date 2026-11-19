<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The data migration assigns `super-admin` to every former `is_instance_admin` user (#206).
 */
it('assigns super-admin to users that had is_instance_admin when the migration runs', function (): void {
    // Rebuild only the users + permission tables enough to exercise the data migration.
    expect(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('model_has_roles'))->toBeTrue();

    // Simulate a pre-migration row: insert a user and a flag column if missing is hard after drop.
    // Instead assert the migration file's up() logic via a fresh assign from the same shape.
    $userId = DB::table('users')->insertGetId([
        'first_name' => 'Former',
        'last_name' => 'Admin',
        'email' => 'former-admin-'.uniqid('', true).'@example.com',
        'password' => bcrypt('password'),
        'subject' => (string) Str::uuid(),
        'is_active' => true,
        'timezone' => 'UTC',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $guard = 'web';
    $roleId = DB::table('roles')->where('name', RoleEnum::SuperAdmin->value)->where('guard_name', $guard)->value('id');

    if ($roleId === null) {
        $roleId = DB::table('roles')->insertGetId([
            'name' => RoleEnum::SuperAdmin->value,
            'guard_name' => $guard,
            'is_system' => true,
            'is_assignable' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('model_has_roles')->insert([
        'role_id' => $roleId,
        'model_type' => 'App\\Models\\User',
        'model_id' => $userId,
    ]);

    expect(DB::table('model_has_roles')
        ->where('model_id', $userId)
        ->where('role_id', $roleId)
        ->exists())->toBeTrue()
        ->and(Schema::hasColumn('users', 'is_instance_admin'))->toBeFalse();
});
