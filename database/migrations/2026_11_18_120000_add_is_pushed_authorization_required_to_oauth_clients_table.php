<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client RFC 9126 §6 `require_pushed_authorization_requests`: when true, the
 * client may start an authorization request only through the PAR endpoint.
 *
 * Defaults to false so existing clients keep working unchanged.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->boolean('is_pushed_authorization_required')->default(false)->after('is_first_party');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dropColumn('is_pushed_authorization_required');
        });
    }
};
