<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet divergence: Signaculum keeps `users.subject` as the public id (and OIDC `sub`);
 * it must not grow a second `public_id` column (#192).
 */
test('users.subject is the fleet public_id and there is no second public_id column', function (): void {
    expect(Schema::hasColumn('users', 'subject'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'public_id'))->toBeFalse();

    $user = User::factory()->create();

    expect($user->subject)->toBeString()->not->toBeEmpty()
        ->and(preg_match('/^[0-9a-f-]{36}$/i', $user->subject) === 1)->toBeTrue();
});
