<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The split migration must turn every existing `name` into first and last name.
 */
test('existing names are split into first and last name', function () {
    $migration = require database_path('migrations/2026_11_06_100000_split_user_name_into_first_and_last_name.php');

    $migration->down();

    DB::table('users')->insert([
        ['name' => 'Ada King Lovelace', 'email' => 'ada@example.test', 'password' => 'x', 'subject' => (string) Str::uuid7()],
        ['name' => 'Plato', 'email' => 'plato@example.test', 'password' => 'x', 'subject' => (string) Str::uuid7()],
    ]);

    $migration->up();

    expect(DB::table('users')->where('email', 'ada@example.test')->first(['first_name', 'last_name']))
        ->first_name->toBe('Ada King')
        ->last_name->toBe('Lovelace');

    expect(DB::table('users')->where('email', 'plato@example.test')->first(['first_name', 'last_name']))
        ->first_name->toBe('Plato')
        ->last_name->toBeNull();
});

test('the full name joins first and last name', function () {
    $user = User::factory()->make(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->toArray())->toHaveKey('name', 'Ada Lovelace');

    $user->last_name = null;

    expect($user->name)->toBe('Ada');
});
