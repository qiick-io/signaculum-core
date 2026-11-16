<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organisation logo and brand colours for login/consent (#67).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('logo_path')->nullable()->after('slug');
            $table->string('logo_dark_path')->nullable()->after('logo_path');
            $table->string('brand_color', 7)->nullable()->after('logo_dark_path');
            $table->string('brand_color_dark', 7)->nullable()->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn([
                'logo_path',
                'logo_dark_path',
                'brand_color',
                'brand_color_dark',
            ]);
        });
    }
};
