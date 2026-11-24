<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Users have a first and a last name; `name` is the joined full name, not a column.
 */
test('the full name joins first and last name', function () {
    $user = User::factory()->make(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->toArray())->toHaveKey('name', 'Ada Lovelace');

    $user->last_name = null;

    expect($user->name)->toBe('Ada');
});
