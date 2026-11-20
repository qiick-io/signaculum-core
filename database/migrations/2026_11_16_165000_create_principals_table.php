<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet principals own API keys; every user gets a `user` principal on create
 * ({@see User::booted}).
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
 * @see knowledge/decisions/2026-11-20-migrations-freeze-at-first-stable-release
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('principals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 32);
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('name');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('principals');
    }
};
