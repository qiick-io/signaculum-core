<?php

declare(strict_types=1);

use App\Enums\RoleEnum;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The data migration assigns `super-admin` to every former `is_instance_admin` user (#206).
 * Rolls back past 220100, inserts a flagged user, then runs the remaining migrations for real.
 */
it('assigns super-admin to flagged users when migrations after 220100 run', function (): void {
    $pendingMigrationNames = [
        '2026_11_19_220400_add_username_to_users_table',
        '2026_11_19_220300_drop_is_instance_admin_from_users_table',
        '2026_11_19_220200_migrate_instance_admins_to_super_admin_role',
    ];

    foreach ($pendingMigrationNames as $migrationName) {
        $path = database_path('migrations/'.$migrationName.'.php');
        $migration = require $path;
        $migration->down();
        DB::table('migrations')->where('migration', $migrationName)->delete();
    }

    expect(Schema::hasColumn('users', 'is_instance_admin'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'username'))->toBeFalse();

    $email = 'former-flagged-'.Str::lower(Str::random(8)).'@example.com';

    $userAttributes = [
        'first_name' => 'Former',
        'last_name' => 'Flagged',
        'email' => $email,
        'password' => bcrypt('not-used-here'),
        'subject' => (string) Str::uuid(),
        'is_active' => true,
        'is_instance_admin' => true,
        'timezone' => 'UTC',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    if (Schema::hasColumn('users', 'email_verified_at')) {
        $userAttributes['email_verified_at'] = now();
    }

    $userId = DB::table('users')->insertGetId($userAttributes);

    expect(DB::table('model_has_roles')->where('model_id', $userId)->exists())->toBeFalse();

    Artisan::call('migrate', ['--force' => true]);

    $roleId = DB::table('roles')
        ->where('name', RoleEnum::SuperAdmin->value)
        ->where('guard_name', 'web')
        ->value('id');

    expect($roleId)->not->toBeNull()
        ->and(DB::table('model_has_roles')
            ->where('model_id', $userId)
            ->where('role_id', $roleId)
            ->exists())->toBeTrue()
        ->and(Schema::hasColumn('users', 'is_instance_admin'))->toBeFalse()
        ->and(Schema::hasColumn('users', 'username'))->toBeTrue();
})->skip(
    fn (): bool => ! Schema::hasTable('migrations'),
    'Migrations table required',
);
