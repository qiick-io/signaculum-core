<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * The `api_keys` columns and indexes the qiick/laravel-api-keys reference schema
 * (`docs/reference-schema.md`) asks for, beyond what Laravel's helpers create on their own.
 */
it('indexes the owner principal on every engine', function (): void {
    $ownerPrincipalIndexes = collect(Schema::getIndexes('api_keys'))
        ->filter(fn (array $index): bool => $index['columns'] === ['owner_principal_id']);

    expect($ownerPrincipalIndexes)->not->toBeEmpty();
});

it('always stores when a key was created and last updated', function (): void {
    $timestampColumns = collect(Schema::getColumns('api_keys'))
        ->whereIn('name', ['created_at', 'updated_at'])
        ->pluck('nullable', 'name');

    expect($timestampColumns->all())->toBe(['created_at' => false, 'updated_at' => false]);
});
