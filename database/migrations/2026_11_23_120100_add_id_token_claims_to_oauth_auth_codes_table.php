<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the user claims the `claims` request parameter asked for in the id_token
 * (OIDC Core §5.5), so the token endpoint releases them there (#262).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->text('id_token_claims')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->dropColumn('id_token_claims');
        });
    }
};
