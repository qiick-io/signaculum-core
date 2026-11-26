<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Every foreign key of the access tables qiick/laravel-access creates has an index of its own:
 * PostgreSQL and SQLite do not add one, so deleting a user would scan these tables.
 */
it('indexes every foreign key of the access tables', function (string $table): void {
    $indexedColumnLists = collect(Schema::getIndexes($table))->pluck('columns');
    $foreignKeys = Schema::getForeignKeys($table);

    expect($foreignKeys)->not->toBeEmpty();

    foreach ($foreignKeys as $foreignKey) {
        $isIndexed = $indexedColumnLists->contains(
            fn (array $indexColumns): bool => array_slice($indexColumns, 0, count($foreignKey['columns'])) === $foreignKey['columns'],
        );

        expect($isIndexed)->toBeTrue("{$table}.".implode(',', $foreignKey['columns']).' has no index');
    }
})->with(['principals', 'user_group_user', 'user_group_role']);
