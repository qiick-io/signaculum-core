<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every user with `is_instance_admin = true` receives the Spatie `super-admin` role before the
 * flag column is dropped (decision 2026-11-19 / #206). Permissions are filled by
 * `signaculum:sync-permissions` on install/upgrade; this migration only ensures the role row
 * and the assignments exist so Gate::before keeps working after the column is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_instance_admin')) {
            return;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $guard = 'web';
        $now = now();

        $roleId = DB::table('roles')->where('name', 'super-admin')->where('guard_name', $guard)->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'super-admin',
                'guard_name' => $guard,
                'is_system' => true,
                'is_assignable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->where('guard_name', $guard)->value('id');

        if ($adminRoleId === null) {
            DB::table('roles')->insert([
                'name' => 'admin',
                'guard_name' => $guard,
                'is_system' => false,
                'is_assignable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $readerRoleId = DB::table('roles')->where('name', 'reader')->where('guard_name', $guard)->value('id');

        if ($readerRoleId === null) {
            DB::table('roles')->insert([
                'name' => 'reader',
                'guard_name' => $guard,
                'is_system' => false,
                'is_assignable' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $modelType = 'App\\Models\\User';

        foreach (DB::table('users')->where('is_instance_admin', true)->pluck('id') as $userId) {
            $exists = DB::table('model_has_roles')
                ->where('role_id', $roleId)
                ->where('model_type', $modelType)
                ->where('model_id', $userId)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('model_has_roles')->insert([
                'role_id' => $roleId,
                'model_type' => $modelType,
                'model_id' => $userId,
            ]);
        }
    }

    public function down(): void
    {
        // Role assignments stay; restoring the flag is handled by the later drop migration's down.
    }
};
