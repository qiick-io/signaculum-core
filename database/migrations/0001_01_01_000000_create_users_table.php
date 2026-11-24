<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users table: every column of a Signaculum account, in one create_ migration before the
 * first stable release (#218). The earlier add_ migrations (subject, names, locale, 2FA,
 * status, lockout, LDAP) are folded in here; a database created by a beta is recreated.
 *
 * @see knowledge/decisions/2026-11-20-migrations-freeze-at-first-stable-release
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // Opaque, never reassigned OpenID Connect subject (`sub`). Clients key their
            // identities on (iss, sub), so it must not leak the auto-increment id or ever change.
            $table->uuid('subject')->nullable()->unique();
            // First and last name, released as `given_name` / `family_name`; `name` is the
            // joined full name (User::name accessor), not a column.
            $table->string('first_name')->default('');
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            // UI language, also released as the OIDC `locale` claim (profile scope).
            $table->string('locale', 10)->nullable();
            // Stable LDAP directory link for read-only sync (#77): the converted objectGUID /
            // entryUUID, null for a local-only account. Missing directory entries are
            // deactivated, never deleted.
            $table->string('ldap_guid')->nullable()->unique();
            $table->string('ldap_dn')->nullable();
            $table->string('username')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->boolean('is_active')->default(true);
            // Brute-force lockout: failed password logins and the moment the account unlocks.
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            // Nullable: LDAP-sourced accounts authenticate by bind, not a local hash (#187).
            $table->string('password')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->string('timezone')->default('UTC');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
