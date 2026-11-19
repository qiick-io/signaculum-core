<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: every activity row may name the organisation it happened in.
 *
 * Uses British `organisation_id` (fleet spelling) even though the table remains
 * `organizations` (declared Signaculum divergence).
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->string('organisation_id', 36)->nullable()->after('causer_id');
            $table->index('organisation_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table): void {
            $table->dropIndex(['organisation_id']);
            $table->dropColumn('organisation_id');
        });
    }
};
