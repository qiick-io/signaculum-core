<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instance-admin API keys for the Admin REST API (/api/v1), on the shared qiick baseline
 * (app-ui docs/api-keys.md): the token is `<key_prefix><secret>`, looked up by its public
 * `key_prefix` (`sig_<live|test>_<id>`), with only the SHA-256 of the full token stored. `previous_key_hash` keeps the secret replaced by
 * the last rotation working until `previous_key_valid_until`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('key_prefix', 32)->unique();
            $table->string('key_hash', 64);
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('owner_principal_id')
                ->nullable()
                ->constrained('principals')
                ->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('scopes');
            $table->json('ip_allowlist')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->string('previous_key_hash', 64)->nullable();
            $table->timestamp('previous_key_valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
