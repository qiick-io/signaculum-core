<?php

declare(strict_types=1);

use App\Enums\PermissionEnum;
use Qiick\Access\Testing\DirectPermissionCheckScanner;

/**
 * qiick/laravel-access AC9: permissions are checked in Policies, the permission middleware and
 * the FormRequest trait. Any other direct `hasPermission()` must be listed in
 * `qiick-standards.json` `access.direct_checks` with a reason, a decision and a review date, and
 * a listed site with no check left fails too, so the list only shrinks.
 */
test('every direct permission check outside Policies is listed', function (): void {
    $violations = (new DirectPermissionCheckScanner(PermissionEnum::class))
        ->scan(base_path(), ['app', 'routes'], base_path('qiick-standards.json'));

    expect($violations)->toBe([]);
});
