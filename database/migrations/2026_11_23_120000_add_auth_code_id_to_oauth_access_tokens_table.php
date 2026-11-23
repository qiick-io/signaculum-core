<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tie every access token to the authorization code it descends from (refreshes
 * inherit it), so a replayed code revokes the whole token family it issued
 * (RFC 6749 §4.1.2, RFC 9700 §4.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->char('auth_code_id', 80)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('auth_code_id');
        });
    }
};
