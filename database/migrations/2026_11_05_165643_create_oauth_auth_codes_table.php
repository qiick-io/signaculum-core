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
        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->index();
            $table->foreignUuid('client_id');
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
            // OpenID Connect context of the code, copied into the id_token at redemption:
            // the client's `nonce` and the time the user authenticated.
            $table->string('nonce', 255)->nullable();
            $table->unsignedBigInteger('auth_time')->nullable();
            // Browser `sid` (OIDC Front-Channel Logout §2 / Back-Channel Logout §2.4).
            $table->string('oidc_session_id', 64)->nullable();
            // RFC 8176 `amr` values for the id_token (OIDC Core §2).
            $table->json('authentication_methods')->nullable();
            // Whether the request asked for `acr`, so the id_token emits it only then (#69).
            $table->boolean('is_acr_requested')->default(false);
            // User claims the `claims` request parameter asked for in the id_token (OIDC Core §5.5, #262).
            $table->text('id_token_claims')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_auth_codes');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
