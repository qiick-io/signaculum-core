<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Activity log table of spatie/laravel-activitylog v5, as in qiick-io/excubia-app.
 *
 * The subject and causer ids are strings, not `nullableMorphs()` (bigint): OAuth clients
 * have UUID keys (Passport 13) while users, API keys and webhooks have integer keys, and
 * both kinds are logged. A bigint column only worked on SQLite, whose dynamic typing stores
 * a UUID in it; MySQL and MariaDB refuse it (1265) and PostgreSQL rejects it (22P02).
 * 64 characters hold a UUID with room for a ULID. The index names stay `subject` / `causer`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->string('event')->nullable();
            $table->string('causer_type')->nullable();
            $table->string('causer_id', 64)->nullable();
            $table->index(['causer_type', 'causer_id'], 'causer');
            // British organisation_id (fleet spelling); table stays `organizations` (declared divergence).
            $table->string('organisation_id', 36)->nullable()->index();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
