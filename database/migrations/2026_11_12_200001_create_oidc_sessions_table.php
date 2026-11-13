<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a Laravel browser session to the OIDC `sid` claim (Front-/Back-Channel Logout §2).
 *
 * Needed so "sign out of that browser" can fan out by `sid` without decrypting the
 * session payload. `client_sessions` still records which clients saw each `sid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oidc_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('laravel_session_id')->unique();
            $table->string('oidc_session_id', 64)->unique();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index('oidc_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_sessions');
    }
};
