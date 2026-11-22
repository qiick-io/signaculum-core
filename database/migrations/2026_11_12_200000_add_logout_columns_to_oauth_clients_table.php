<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OIDC RP-Initiated Logout and Back-Channel Logout registration fields on each client.
 *
 * Passport owns `oauth_clients`; these columns sit beside its schema. Post-logout
 * redirect URIs match exactly at runtime (same rule as `redirect_uris`).
 *
 * `post_logout_redirect_uris` is nullable with no default: MySQL refuses a literal default on
 * a JSON column (error 1101, "JSON column can't have a default value"), so `default('[]')`
 * made the whole install impossible there while PostgreSQL and MariaDB accepted it. A client
 * that registered none reads as null, and every read site already treats that as the empty
 * list. An expression default would work on MySQL 8.0.13+ only, which is not portable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->json('post_logout_redirect_uris')->nullable();
            $table->string('backchannel_logout_uri')->nullable();
            $table->boolean('is_backchannel_logout_session_required')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dropColumn([
                'post_logout_redirect_uris',
                'backchannel_logout_uri',
                'is_backchannel_logout_session_required',
            ]);
        });
    }

    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
