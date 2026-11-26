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
        // Column contract of qiick/laravel-api-keys (docs/reference-schema.md, v0.3.0-beta.1):
        // the package ships no migration, the product owns the table.
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('key_prefix', 32)->unique();
            $table->string('key_hash', 64)->unique();
            // 1 canonical, 2 canonical with checksum (new issuance); 0 would be a product legacy format.
            $table->unsignedSmallInteger('token_version')->default(1);
            $table->string('hash_algorithm', 16)->default('sha256');
            $table->string('previous_key_prefix', 32)->nullable()->index();
            $table->string('previous_key_hash', 64)->nullable();
            $table->dateTime('previous_key_valid_until')->nullable();
            // `api_key` only: Signaculum issues no `oauth_grant` rows.
            $table->string('credential_kind', 16)->default('api_key');
            $table->uuid('oauth_client_id')->nullable()->index();
            // Never cascade-erase keys with the user; revoke or reassign first (core-hardening).
            $table->foreignId('owner_id')->index()->constrained('users')->restrictOnDelete();
            // Every key has an owner principal, which cannot be deleted under it (conformance
            // C10, laravel-access design §15.4): a principal is deactivated, never deleted.
            $table->foreignUuid('owner_principal_id')
                ->index()
                ->constrained('principals')
                ->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->json('scopes');
            $table->json('ip_allowlist')->nullable();
            $table->json('restrictions')->nullable();
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->unsignedInteger('rate_limit_per_day')->nullable();
            $table->unsignedInteger('rate_limit_per_month')->nullable();
            $table->json('metadata')->nullable();
            // Future-dated columns are dateTime (never MySQL TIMESTAMP — 2038). Every key expires,
            // at most api.key_max_lifetime_days after creation or rotation (decision 2026-11-22).
            $table->dateTime('expires_at')->index();
            $table->timestamp('expiry_notified_at')->nullable();
            $table->timestamp('expiry_notice_claimed_at')->nullable();
            // The run holding the lease (qiick/laravel-api-keys 0.3.0-beta.3 claim contract).
            $table->string('expiry_notice_claim_token', 64)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->string('last_used_user_agent', 512)->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('exposed_at')->nullable()->index();
            $table->string('exposure_source', 64)->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('revoked_reason', 32)->nullable();
            $table->text('revocation_note')->nullable();
            $table->index(['credential_kind', 'revoked_at']);
            // Non-null, as the package's reference schema lists them (`timestamps()` allows NULL).
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
