<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store whether the authorization request asked for `acr` so the id_token can
 * emit `urn:qiick:loa:pwd` / `urn:qiick:loa:mfa` only when requested (#69).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->boolean('is_acr_requested')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('oauth_auth_codes', function (Blueprint $table): void {
            $table->dropColumn('is_acr_requested');
        });
    }
};
