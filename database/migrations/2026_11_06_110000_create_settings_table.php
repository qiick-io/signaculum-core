<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application settings edited from the UI (SettingsRepositoryService).
 *
 * Fleet scoped shape: (scope, scope_id, group, key) unique. Instance rows use
 * scope=`instance`, scope_id=null, group=`app`. Column lengths stay short enough
 * for a utf8mb4 unique index on MySQL (4 × 4 bytes × lengths ≤ 3072).
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
 * @see knowledge/decisions/2026-11-20-migrations-freeze-at-first-stable-release
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32);
            $table->string('scope_id', 36)->nullable();
            $table->string('group', 64);
            $table->string('key', 191);
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'scope_id', 'group', 'key'], 'settings_scope_unique');
            $table->index(['scope', 'scope_id', 'group'], 'settings_scope_group_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
