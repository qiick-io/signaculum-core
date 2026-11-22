<?php

declare(strict_types=1);

/**
 * Normalized schema fingerprint for proving migrate:fresh identity across engines.
 *
 * Ignores column ordinal position; includes name, type, nullable, default, indexes, FKs.
 * Restricts MySQL / MariaDB to the connected database (getTableListing returns every schema).
 */

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$connection = DB::connection();
$driver = $connection->getDriverName();
$database = (string) $connection->getDatabaseName();

$rawTableNames = collect(Schema::getTableListing())
    ->map(function (string $tableName) use ($driver, $database): ?string {
        if (str_contains($tableName, '.')) {
            [$schemaName, $bareTableName] = explode('.', $tableName, 2);

            if (in_array($driver, ['mysql', 'mariadb'], true) && $schemaName !== $database) {
                return null;
            }

            return $bareTableName;
        }

        return $tableName;
    })
    ->filter()
    ->unique()
    ->sort()
    ->values();

$fingerprint = [
    'driver' => $driver,
    'database' => $database,
    'tables' => [],
];

foreach ($rawTableNames as $table) {
    if ($table === 'migrations') {
        continue;
    }

    $qualifiedTable = in_array($driver, ['mysql', 'mariadb'], true)
        ? $database.'.'.$table
        : $table;

    $columns = collect(Schema::getColumns($qualifiedTable))
        ->map(fn (array $column): array => [
            'name' => $column['name'],
            'type_name' => $column['type_name'] ?? $column['type'] ?? null,
            'type' => $column['type'] ?? null,
            'nullable' => (bool) ($column['nullable'] ?? false),
            'default' => $column['default'] ?? null,
            'auto_increment' => (bool) ($column['auto_increment'] ?? false),
        ])
        ->sortBy('name')
        ->values()
        ->all();

    $indexes = collect(Schema::getIndexes($qualifiedTable))
        ->map(fn (array $index): array => [
            'name' => $index['name'],
            'columns' => $index['columns'],
            'unique' => (bool) ($index['unique'] ?? false),
            'primary' => (bool) ($index['primary'] ?? false),
        ])
        ->sortBy('name')
        ->values()
        ->all();

    $foreignKeys = collect(Schema::getForeignKeys($qualifiedTable))
        ->map(fn (array $foreignKey): array => [
            'name' => $foreignKey['name'],
            'columns' => $foreignKey['columns'],
            'foreign_table' => $foreignKey['foreign_table'],
            'foreign_columns' => $foreignKey['foreign_columns'],
            'on_update' => $foreignKey['on_update'] ?? null,
            'on_delete' => $foreignKey['on_delete'] ?? null,
        ])
        ->sortBy('name')
        ->values()
        ->all();

    $fingerprint['tables'][$table] = [
        'columns' => $columns,
        'indexes' => $indexes,
        'foreign_keys' => $foreignKeys,
    ];
}

ksort($fingerprint['tables']);

echo json_encode($fingerprint, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
