<?php

declare(strict_types=1);

/**
 * Fleet alignment: Spatie's team column is organisation_id while teams stay off
 * (decision 2026-11-22 access package tables / #223 prep).
 */
test('Spatie team_foreign_key is organisation_id and teams stay off', function (): void {
    expect(config('permission.teams'))->toBeFalse()
        ->and(config('permission.column_names.team_foreign_key'))->toBe('organisation_id');
});
