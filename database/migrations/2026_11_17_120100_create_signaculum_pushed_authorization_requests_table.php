<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFC 9126 PAR: store pushed authorization requests until single-use redeem.
 *
 * Named `signaculum_pushed_authorization_requests` so the standard
 * `oauth_pushed_authorization_requests` stays free for qiick/laravel-mcp-oauth
 * (decision 2026-11-22 standard names in base packages / #238).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signaculum_pushed_authorization_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('request_uri')->unique();
            $table->uuid('client_id')->index();
            $table->json('parameters');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signaculum_pushed_authorization_requests');
    }
};
