<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Models\User;

/**
 * Admin REST API `/api/v1/users` beyond the list and create already covered by AdminApiTest:
 * the write endpoints, the list filters and what a `users:write` key may never set (#180).
 */
it('refuses the user write endpoints without a key, and changes nothing', function (): void {
    $user = User::factory()->create(['email' => 'untouched@example.test', 'first_name' => 'Un']);

    foreach ([
        fn () => $this->patchJson('/api/v1/users/'.$user->subject, [
            'first_name' => 'Hijacked',
            'email' => 'hijacked@example.test',
        ]),
        fn () => $this->postJson('/api/v1/users/'.$user->subject.'/deactivate'),
        fn () => $this->postJson('/api/v1/users/'.$user->subject.'/reset-two-factor'),
    ] as $call) {
        $call()
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Missing API key.', 'error' => 'invalid_token']);
    }

    $unchanged = $user->fresh();

    expect($unchanged?->email)->toBe('untouched@example.test')
        ->and($unchanged?->first_name)->toBe('Un')
        ->and($unchanged?->is_active)->toBeTrue();
});

it('refuses a users read key on the user write endpoints, and changes nothing', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $user = User::factory()->create(['email' => 'readonly@example.test']);

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, ['first_name' => 'Hijacked', 'email' => 'hijacked@example.test'])
        ->assertForbidden()
        ->assertJsonPath('scope', 'users:write');

    $this->withToken($plain)->postJson('/api/v1/users/'.$user->subject.'/deactivate')
        ->assertForbidden()
        ->assertJsonPath('scope', 'users:write');

    $this->withToken($plain)->postJson('/api/v1/users/'.$user->subject.'/reset-two-factor')
        ->assertForbidden()
        ->assertJsonPath('scope', 'users:write');

    $unchanged = $user->fresh();

    expect($unchanged?->email)->toBe('readonly@example.test')
        ->and($unchanged?->is_active)->toBeTrue();
});

it('refuses a malformed user update payload field by field', function (array $payload, array $invalidFields): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create(['email' => 'target@example.test', 'first_name' => 'Target']);
    User::factory()->create(['email' => 'taken@example.test']);

    $this->withToken($plain)->patchJson('/api/v1/users/'.$user->subject, $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidFields);

    expect($user->fresh()?->email)->toBe('target@example.test')
        ->and($user->fresh()?->first_name)->toBe('Target');
})->with([
    'nothing at all' => [[], ['first_name', 'email']],
    'no first name' => [['email' => 'fine@example.test'], ['first_name']],
    'no email' => [['first_name' => 'Ada'], ['email']],
    'email not an email' => [['first_name' => 'Ada', 'email' => 'not-an-email'], ['email']],
    'first name too long' => [['first_name' => str_repeat('a', 256), 'email' => 'fine@example.test'], ['first_name']],
    'last name is an array' => [['first_name' => 'Ada', 'last_name' => ['Lovelace'], 'email' => 'fine@example.test'], ['last_name']],
]);

it('refuses a user update that takes an email another account already has', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create(['email' => 'mine@example.test']);
    User::factory()->create(['email' => 'theirs@example.test']);

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, ['first_name' => 'Ada', 'email' => 'theirs@example.test'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email']);

    expect($user->fresh()?->email)->toBe('mine@example.test');
});

it('lets a user keep their own email on update', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create(['email' => 'same@example.test', 'first_name' => 'Old']);

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, ['first_name' => 'New', 'email' => 'same@example.test'])
        ->assertOk()
        ->assertJsonPath('data.first_name', 'New')
        ->assertJsonPath('data.email', 'same@example.test');
});

it('refuses a weak password on create and on update', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create(['email' => 'weak@example.test']);

    $this->withToken($plain)
        ->postJson('/api/v1/users', [
            'first_name' => 'Ada',
            'email' => 'new-weak@example.test',
            'password' => 'short',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, [
            'first_name' => 'Ada',
            'email' => 'weak@example.test',
            'password' => 'short',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(User::query()->where('email', 'new-weak@example.test')->exists())->toBeFalse();
});

it('never lets a users write key grant instance admin or reactivate an account', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/users', [
            'first_name' => 'Esc',
            'last_name' => 'Alate',
            'email' => 'escalate@example.test',
            'password' => 'Password1!Password1!',
            'is_instance_admin' => true,
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_instance_admin', false);

    $created = User::query()->where('email', 'escalate@example.test')->firstOrFail();

    expect($created->isSuperAdmin())->toBeFalse();

    $deactivated = User::factory()->create(['is_active' => false, 'email' => 'dormant@example.test']);

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$deactivated->subject, [
            'first_name' => 'Dormant',
            'email' => 'dormant@example.test',
            'is_active' => true,
            'is_instance_admin' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.is_instance_admin', false);

    $stillDormant = $deactivated->fresh();

    expect($stillDormant?->is_active)->toBeFalse()
        ->and($stillDormant?->isSuperAdmin())->toBeFalse();
});

it('answers 404 for a user id that does not exist', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);

    $this->withToken($plain)->getJson('/api/v1/users/00000000-0000-4000-8000-000000000000')->assertNotFound();
    $this->withToken($plain)->patchJson('/api/v1/users/00000000-0000-4000-8000-000000000000', ['first_name' => 'A', 'email' => 'a@example.test'])->assertNotFound();
    $this->withToken($plain)->postJson('/api/v1/users/00000000-0000-4000-8000-000000000000/deactivate')->assertNotFound();
    $this->withToken($plain)->postJson('/api/v1/users/00000000-0000-4000-8000-000000000000/reset-two-factor')->assertNotFound();
});

it('updates a user name email and password', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test']);
    $passwordBefore = $user->password;

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, [
            'first_name' => 'Grace',
            'last_name' => 'Murray Hopper',
            'email' => 'grace.hopper@example.test',
            'password' => 'Password1!Password1!',
        ])
        ->assertOk()
        ->assertJsonPath('data.last_name', 'Murray Hopper')
        ->assertJsonPath('data.email', 'grace.hopper@example.test');

    $updated = $user->fresh();

    expect($updated?->last_name)->toBe('Murray Hopper')
        ->and($updated?->email)->toBe('grace.hopper@example.test')
        ->and($updated?->password)->not->toBe($passwordBefore);
});

it('leaves the password alone when the update omits it', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create(['email' => 'keep@example.test']);
    $passwordBefore = $user->password;

    $this->withToken($plain)
        ->patchJson('/api/v1/users/'.$user->subject, ['first_name' => 'Kept', 'email' => 'keep@example.test'])
        ->assertOk();

    expect($user->fresh()?->password)->toBe($passwordBefore);
});

it('deactivates a user through the api', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->create(['email' => 'leaving@example.test']);

    $this->withToken($plain)
        ->postJson('/api/v1/users/'.$user->subject.'/deactivate')
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($user->fresh()?->is_active)->toBeFalse();
});

it('refuses to deactivate the last instance admin', function (): void {
    ['plain' => $plain, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);

    // The key's own owner is the only instance admin on this instance. A key never acts on a
    // super-admin at all (no super-admin authority for API actors), so the refusal comes before
    // the last-admin rule, which the console path still enforces with a 422.
    expect(User::role('super-admin')->count())->toBe(1);

    $this->withToken($plain)
        ->postJson('/api/v1/users/'.$admin->subject.'/deactivate')
        ->assertForbidden();

    expect($admin->fresh()?->is_active)->toBeTrue();
});

it('resets a two factor enrolment through the api', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $user = User::factory()->withTwoFactor()->create(['email' => 'locked-out@example.test']);

    expect($user->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->withToken($plain)
        ->postJson('/api/v1/users/'.$user->subject.'/reset-two-factor')
        ->assertOk()
        ->assertJsonPath('data.has_two_factor', false);

    $reset = $user->fresh();

    expect($reset?->two_factor_secret)->toBeNull()
        ->and($reset?->two_factor_confirmed_at)->toBeNull();
});

it('filters the users list by status and ignores an unknown status', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    User::factory()->create(['email' => 'active-one@example.test']);
    User::factory()->create(['email' => 'dormant-one@example.test', 'is_active' => false]);

    $active = $this->withToken($plain)->getJson('/api/v1/users?status=active')->assertOk();
    $deactivated = $this->withToken($plain)->getJson('/api/v1/users?status=deactivated')->assertOk();
    $unknownStatus = $this->withToken($plain)->getJson('/api/v1/users?status=pending')->assertOk();

    expect(collect($active->json('data'))->pluck('email'))->toContain('active-one@example.test')
        ->not->toContain('dormant-one@example.test')
        ->and(collect($deactivated->json('data'))->pluck('email')->all())->toBe(['dormant-one@example.test'])
        // An unrecognised status falls back to `active`, it does not widen the list.
        ->and(collect($unknownStatus->json('data'))->pluck('email'))->not->toContain('dormant-one@example.test');
});

it('searches sorts and clamps the users list', function (): void {
    ['plain' => $plain, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);
    User::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Zebra', 'email' => 'zoe@example.test']);

    $this->withToken($plain)->getJson('/api/v1/users?search=Lovelace')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'ada@example.test');

    $this->withToken($plain)->getJson('/api/v1/users?search=zoe@example.test')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $byName = $this->withToken($plain)->getJson('/api/v1/users?sort=name&direction=asc')->assertOk();
    $byEmailDescending = $this->withToken($plain)->getJson('/api/v1/users?sort=email&direction=desc')->assertOk();

    // Compare the two fixture users only: the API-key owner is also in the list and
    // its factory first_name would make `data.0` flake across engines and seeds.
    $firstNamesAscending = collect($byName->json('data'))->pluck('first_name');
    $emailsDescending = collect($byEmailDescending->json('data'))->pluck('email');

    expect($firstNamesAscending->search('Ada'))->not->toBeFalse()
        ->and($firstNamesAscending->search('Zoe'))->not->toBeFalse()
        ->and($firstNamesAscending->search('Ada'))->toBeLessThan($firstNamesAscending->search('Zoe'))
        ->and($emailsDescending->search('zoe@example.test'))->not->toBeFalse()
        ->and($emailsDescending->search('ada@example.test'))->not->toBeFalse()
        ->and($emailsDescending->search('zoe@example.test'))
        ->toBeLessThan($emailsDescending->search('ada@example.test'));

    // An unsortable column is ignored rather than passed to the database.
    $this->withToken($plain)->getJson('/api/v1/users?sort=password&direction=asc')->assertOk();

    config(['api.max_per_page' => 2]);

    $this->withToken($plain)->getJson('/api/v1/users?per_page=50')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');

    expect($admin->isSuperAdmin())->toBeTrue();
});

it('never exposes a password hash or a two factor secret in a user payload', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $user = User::factory()->withTwoFactor()->create(['email' => 'secretive@example.test']);

    foreach ([
        $this->withToken($plain)->getJson('/api/v1/users')->assertOk(),
        $this->withToken($plain)->getJson('/api/v1/users/'.$user->subject)->assertOk(),
    ] as $response) {
        $body = $response->getContent();

        expect($body)->not->toContain('password')
            ->not->toContain('two_factor_secret')
            ->not->toContain('two_factor_recovery_codes')
            ->not->toContain('remember_token');
    }
});
