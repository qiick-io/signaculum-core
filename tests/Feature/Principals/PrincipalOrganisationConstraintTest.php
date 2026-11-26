<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Qiick\Access\Testing\AssertsPrincipalsSchema;

/**
 * The `principals` schema is qiick/laravel-access' (design §15), with Signaculum's settings:
 * organisations required, `instance_service` the only instance type, and a restrict foreign key
 * to `organizations` added by Signaculum's own migration. The package's probe proves it on the
 * migrated database (§15.6, P9): one principal per membership, the organisation rule both ways,
 * the instance singleton, a user with principals cannot be deleted, and an organisation with
 * principals cannot be deleted.
 */
test('the principals schema enforces the package rules with organisations required', function (): void {
    $probe = new AssertsPrincipalsSchema(
        DB::connection(),
        fn (): int => User::factory()->create()->id,
        fn (): string => (string) Organization::factory()->create()->getKey(),
        fn (string $organisationId) => DB::table('organizations')->where('id', $organisationId)->delete(),
    );

    $probe->assertShape();
    $probe->assertConstraints();

    expect(true)->toBeTrue();
});
