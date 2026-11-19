<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instance administration is expressed by Spatie roles (`super-admin` / `admin`) after
 * {@see 2026_11_19_220200_migrate_instance_admins_to_super_admin_role}.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_instance_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_instance_admin');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'is_instance_admin')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_instance_admin')->default(false)->after('email_verified_at');
        });
    }
};
