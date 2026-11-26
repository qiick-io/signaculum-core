<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (browser OIDC session, client) the first time an authorization code
 * is redeemed. Back-channel logout fans out to every open row for that `sid`/`sub`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('client_id')->index()->constrained('oauth_clients')->cascadeOnDelete();
            $table->string('oidc_session_id', 64)->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_id', 'oidc_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_sessions');
    }
};
