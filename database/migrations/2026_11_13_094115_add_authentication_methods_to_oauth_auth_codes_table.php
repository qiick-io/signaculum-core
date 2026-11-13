<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store RFC 8176 `amr` values with the authorization code so the token endpoint
 * can copy them into the id_token (OIDC Core §3.1.3.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->json('authentication_methods')->nullable()->after('oidc_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->dropColumn('authentication_methods');
        });
    }
};
