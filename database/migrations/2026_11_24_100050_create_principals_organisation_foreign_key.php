<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Qiick\Access\Laravel\AccessSchema;

/**
 * qiick/laravel-access ships the `principals` table without a foreign key on `organisation_id`:
 * the product adds it to its own organisations table (design §15.5). Restrict, never cascade or
 * set null: deleting an organisation is an offboarding job that keeps its principals as evidence
 * (DeleteOrganizationAction), and MySQL / MariaDB refuse CASCADE or SET NULL on a base column of
 * the stored generated `organisation_key` anyway.
 *
 * SQLite rebuilds the table to add the key and drops the package's triggers with it, so the
 * migration restores them (AccessSchema::ensureConstraints() is idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('principals', function (Blueprint $table): void {
            $table->foreign('organisation_id')->references('id')->on('organizations')->restrictOnDelete();
        });

        AccessSchema::ensureConstraints();
    }

    public function down(): void
    {
        Schema::table('principals', function (Blueprint $table): void {
            $table->dropForeign(['organisation_id']);
        });

        AccessSchema::ensureConstraints();
    }
};
