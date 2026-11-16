<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time email sign-in codes (#121): hashed at rest, short TTL, single use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_login_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->index();
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_login_codes');
    }
};
