<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Opaque, never reassigned OpenID Connect subject (`sub`) per user. Clients key their
 * identities on (iss, sub), so it must not leak the auto-increment id or ever change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('subject')->nullable()->unique()->after('id');
        });

        DB::table('users')->whereNull('subject')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['subject' => (string) Str::uuid7()]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['subject']);
            $table->dropColumn('subject');
        });
    }
};
