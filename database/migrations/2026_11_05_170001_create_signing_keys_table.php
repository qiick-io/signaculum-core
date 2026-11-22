<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RSA keys that sign id_tokens. The newest key without `retired_at` signs; retired keys
 * stay published in the JWKS for an overlap window. Private keys are encrypted with APP_KEY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signing_keys', function (Blueprint $table): void {
            $table->id();
            $table->string('kid', 64)->unique();
            $table->string('algorithm', 16);
            $table->text('public_key');
            $table->text('private_key');
            $table->timestamp('retired_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signing_keys');
    }
};
