<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OIDC Front-Channel Logout 1.0 client registration fields (§3).
 *
 * `frontchannel_logout_session_required` defaults to false (spec §3); the column
 * is prefixed `is_` to match `is_backchannel_logout_session_required`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->string('frontchannel_logout_uri')->nullable()->after('is_backchannel_logout_session_required');
            $table->boolean('is_frontchannel_logout_session_required')->default(false)->after('frontchannel_logout_uri');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table): void {
            $table->dropColumn([
                'frontchannel_logout_uri',
                'is_frontchannel_logout_session_required',
            ]);
        });
    }
};
