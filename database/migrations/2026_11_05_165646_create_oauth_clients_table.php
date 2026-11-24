<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->nullableMorphs('owner');
            $table->string('name');
            $table->string('secret')->nullable();
            $table->string('provider')->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            // First-party qiick apps may skip the consent screen (Client::skipsAuthorization()).
            $table->boolean('is_first_party')->default(false);
            // RFC 9126 §6 `require_pushed_authorization_requests`.
            $table->boolean('is_pushed_authorization_required')->default(false);
            // OIDC RP-Initiated and Back-Channel Logout registration. Post-logout redirect URIs
            // match exactly. No default on the JSON column: MySQL refuses a literal default there
            // (error 1101), and every read site treats null as the empty list.
            $table->json('post_logout_redirect_uris')->nullable();
            $table->string('backchannel_logout_uri')->nullable();
            $table->boolean('is_backchannel_logout_session_required')->default(true);
            // OIDC Front-Channel Logout 1.0 §3; the session flag defaults to false (§3).
            $table->string('frontchannel_logout_uri')->nullable();
            $table->boolean('is_frontchannel_logout_session_required')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
