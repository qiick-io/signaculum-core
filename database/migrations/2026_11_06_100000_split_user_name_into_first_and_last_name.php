<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First and last name instead of one `name`, as in externa-core and Excubia. They are
 * released as the OIDC `given_name` / `family_name` claims; `name` stays available as
 * the joined full name (User::name accessor).
 *
 * Backfill: the last word of the old name becomes the last name, the rest the first name
 * ("Ada King Lovelace" → "Ada King" + "Lovelace"); a single word is a first name only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->default('')->after('subject');
            $table->string('last_name')->nullable()->after('first_name');
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $nameParts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
            $lastName = count($nameParts) > 1 ? array_pop($nameParts) : null;

            DB::table('users')->where('id', $user->id)->update([
                'first_name' => implode(' ', $nameParts),
                'last_name' => $lastName,
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->default('')->after('subject');
        });

        DB::table('users')->orderBy('id')->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update([
                'name' => trim($user->first_name.' '.$user->last_name),
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
