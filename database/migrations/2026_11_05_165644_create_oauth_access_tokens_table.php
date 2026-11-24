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
        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->char('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignUuid('client_id');
            $table->string('name')->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamps();
            $table->dateTime('expires_at')->nullable();
            // Root of the token family of an authorization code (refreshes inherit it), so a
            // replayed code revokes the whole family it issued (RFC 6749 §4.1.2, RFC 9700 §4.5).
            $table->char('auth_code_id', 80)->nullable()->index();
            // Root of the token family of a device code grant (RFC 9700 §4.14.2); the
            // authorization code root is `auth_code_id`.
            $table->char('device_code_id', 80)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_access_tokens');
    }

    /**
     * Get the migration connection name.
     */
    public function getConnection(): ?string
    {
        return $this->connection ?? config('passport.connection');
    }
};
