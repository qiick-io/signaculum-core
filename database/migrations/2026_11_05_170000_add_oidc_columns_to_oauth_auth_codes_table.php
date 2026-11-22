<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OpenID Connect context of an authorization code: the client's `nonce` and the time
 * the user authenticated. Both are copied into the id_token when the code is redeemed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->string('nonce', 255)->nullable();
            $table->unsignedBigInteger('auth_time')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->dropColumn(['nonce', 'auth_time']);
        });
    }
};
