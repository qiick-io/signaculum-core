<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LDAP-sourced accounts must not keep a local password the directory cannot revoke (#187).
 *
 * Makes `users.password` nullable and clears every existing LDAP-linked hash so bind-on-login
 * is the only credential path. Signaculum is unreleased; discarding those hashes is intentional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable()->change();
        });

        DB::table('users')->whereNotNull('ldap_guid')->update(['password' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('ldap_guid')->whereNull('password')->update([
            'password' => '',
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('password')->nullable(false)->change();
        });
    }
};
