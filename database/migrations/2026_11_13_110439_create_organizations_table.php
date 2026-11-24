<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organisations for the `qiick.orgs` claim (id = stable UUID, slug unique).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            // Members must enrol a second factor before finishing an authorization (#120).
            $table->boolean('is_mfa_required')->default(false);
            // Logo and brand colours on login and consent for this organisation (#67).
            $table->string('logo_path')->nullable();
            $table->string('logo_dark_path')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('brand_color_dark', 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
