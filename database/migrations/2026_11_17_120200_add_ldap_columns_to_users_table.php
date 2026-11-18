<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stable LDAP directory link for read-only sync (#77).
 *
 * `ldap_guid` holds the converted objectGUID / entryUUID; null means a local-only account.
 * Missing directory entries are deactivated, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('ldap_guid')->nullable()->unique()->after('email');
            $table->string('ldap_dn')->nullable()->after('ldap_guid');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['ldap_guid', 'ldap_dn']);
        });
    }
};
