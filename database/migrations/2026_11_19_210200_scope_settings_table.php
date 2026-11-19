<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: settings become scoped like externa-core.
 *
 * Shape: (scope, scope_id, group, key) unique. Existing flat rows move to
 * scope=`instance`, scope_id=null, group=`app` — SettingsRepositoryService
 * never grouped keys by a dotted prefix, so the full key is kept as `key`.
 *
 * Column lengths stay short enough for a utf8mb4 unique index on MySQL
 * (4 × 4 bytes × lengths ≤ 3072).
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings_scoped', function (Blueprint $table): void {
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

        $existingRows = DB::table('settings')->orderBy('id')->get();

        foreach ($existingRows as $existingRow) {
            DB::table('settings_scoped')->insert([
                'scope' => 'instance',
                'scope_id' => null,
                'group' => 'app',
                'key' => $existingRow->key,
                'value' => $existingRow->value,
                'created_at' => $existingRow->created_at,
                'updated_at' => $existingRow->updated_at,
            ]);
        }

        Schema::drop('settings');
        Schema::rename('settings_scoped', 'settings');
    }

    public function down(): void
    {
        Schema::create('settings_flat', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        $scopedRows = DB::table('settings')->orderBy('id')->get();

        foreach ($scopedRows as $scopedRow) {
            $flatKey = $scopedRow->group === 'app'
                ? $scopedRow->key
                : $scopedRow->group.'.'.$scopedRow->key;

            DB::table('settings_flat')->insert([
                'key' => $flatKey,
                'value' => $scopedRow->value,
                'created_at' => $scopedRow->created_at,
                'updated_at' => $scopedRow->updated_at,
            ]);
        }

        Schema::drop('settings');
        Schema::rename('settings_flat', 'settings');
    }
};
