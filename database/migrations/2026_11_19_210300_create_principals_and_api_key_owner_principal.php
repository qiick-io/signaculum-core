<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fleet core schema: principals own API keys; every user gets a `user` principal.
 *
 * `api_keys.owner_id` stays the authorisation subject; `owner_principal_id` is the
 * additive fleet owner. Existing keys are backfilled from their owner user.
 *
 * @see knowledge/decisions/2026-11-19-fleet-core-schema
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

        $users = DB::table('users')->select(['id', 'first_name', 'last_name', 'email', 'created_at', 'updated_at'])->get();

        foreach ($users as $user) {
            $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

            if ($name === '') {
                $name = (string) $user->email;
            }

            DB::table('principals')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'user',
                'user_id' => $user->id,
                'organisation_id' => null,
                'name' => $name,
                'deactivated_at' => null,
                'created_at' => $user->created_at ?? now(),
                'updated_at' => $user->updated_at ?? now(),
            ]);
        }

        Schema::table('api_keys', function (Blueprint $table): void {
            $table->foreignUuid('owner_principal_id')
                ->nullable()
                ->after('owner_id')
                ->constrained('principals')
                ->nullOnDelete();
        });

        $principalsByUserId = DB::table('principals')
            ->where('type', 'user')
            ->whereNotNull('user_id')
            ->pluck('id', 'user_id');

        foreach (DB::table('api_keys')->select(['id', 'owner_id'])->get() as $apiKey) {
            $principalId = $principalsByUserId[$apiKey->owner_id] ?? null;

            if ($principalId === null) {
                continue;
            }

            DB::table('api_keys')->where('id', $apiKey->id)->update([
                'owner_principal_id' => $principalId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_principal_id');
        });

        Schema::dropIfExists('principals');
    }
};
