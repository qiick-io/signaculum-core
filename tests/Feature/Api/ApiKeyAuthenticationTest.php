<?php

declare(strict_types=1);

use App\Actions\RevokeApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;

/**
 * Every way the Admin REST API refuses a Bearer API key, in the shared RFC 6750 shape, plus the
 * rotation grace window and the separation from the session guard (app-ui docs/api-keys.md).
 */
function assertInvalidToken(TestResponse $response, string $message): void
{
    $response->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer error="invalid_token"')
        ->assertExactJson(['message' => $message, 'error' => 'invalid_token']);
}

it('refuses a request without a key', function (): void {
    assertInvalidToken($this->getJson('/api/v1/users'), 'Missing API key.');
});

it('refuses a token that does not have the token shape', function (): void {
    assertInvalidToken($this->withToken('sk_'.str_repeat('a', 40))->getJson('/api/v1/users'), 'Invalid API key.');
});

it('answers an unknown key and a wrong secret for a real key the same way', function (): void {
    ['apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $unknownKey = $this->withToken('sig_test_Unknown1'.str_repeat('a', 32))->getJson('/api/v1/users');
    $wrongSecret = $this->withToken($apiKey->key_prefix.str_repeat('a', 32))->getJson('/api/v1/users');

    assertInvalidToken($unknownKey, 'Invalid API key.');
    assertInvalidToken($wrongSecret, 'Invalid API key.');
    expect($wrongSecret->getContent())->toBe($unknownKey->getContent());
});

it('refuses an expired key', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $apiKey->forceFill(['expires_at' => now()->subSecond()])->saveQuietly();

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'API key has expired.');
});

it('refuses a revoked key on the very next request', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();

    app(RevokeApiKeyAction::class)->execute($apiKey, $admin);

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'API key has been revoked.');
});

/**
 * The 'not json' case runs on SQLite only, where a json column is plain TEXT. PostgreSQL,
 * MySQL and MariaDB validate the column, so a key there can never hold such a row and the
 * guard's unparseable branch is only reachable on SQLite: the skip states that fact, it is
 * not a workaround. Without it the case fails on the fixture update, before the request runs.
 *
 * Observed when writing `users:read` into `api_keys.scopes` (#158, as in externa-core#790):
 * - pgsql: SQLSTATE[22P02] invalid input syntax for type json
 * - mysql: SQLSTATE[22032] 3140 Invalid JSON text: "Invalid value." at position 0
 * - mariadb: SQLSTATE[23000] 4025 CONSTRAINT `api_keys.scopes` failed
 */
it('refuses a key whose stored scopes no longer parse', function (string $storedScopes): void {
    if (DB::getDriverName() !== 'sqlite' && ! json_validate($storedScopes)) {
        $this->markTestSkipped('A non-JSON scopes value is only storable on SQLite.');
    }

    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    DB::table('api_keys')->where('id', $apiKey->getKey())->update(['scopes' => $storedScopes]);

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'API key has no valid scopes.');
})->with([
    'empty list' => '[]',
    'json null' => 'null',
    'not json' => 'users:read',
    'unknown scope' => '["users:admin"]',
    'known next to unknown' => '["users:read", "keys:write"]',
    'a map' => '{"users": "read"}',
]);

it('refuses a key whose owner was deactivated or is no longer an instance admin', function (array $ownerChange): void {
    ['plain' => $plain, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $admin->forceFill($ownerChange)->save();

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'API key owner may not use the API.');
})->with([
    'deactivated' => [['is_active' => false]],
    'demoted' => [['is_instance_admin' => false]],
]);

it('refuses a request from outside the ip allowlist', function (): void {
    ['plain' => $plain] = adminApiKey(apiKeyData: new ApiKeyData('Office only', [ApiKeyScopeEnum::UsersRead->value], ipAllowlist: ['10.0.0.0/8']));

    $this->withToken($plain)->getJson('/api/v1/users')
        ->assertForbidden()
        ->assertJsonPath('message', 'API key is not allowed from this IP address.');

    $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->withToken($plain)->getJson('/api/v1/users')->assertOk();
});

/**
 * The 'not json' case runs on SQLite only, for the same reason as the stored scopes above:
 * PostgreSQL, MySQL and MariaDB refuse a non-JSON value in the `api_keys.ip_allowlist` json
 * column on write, so the unparseable branch is only reachable on SQLite.
 *
 * Observed when writing `127.0.0.1` into `api_keys.ip_allowlist` (#158):
 * - pgsql: SQLSTATE[22P02] invalid input syntax for type json
 * - mysql: SQLSTATE[22032] 3140 Invalid JSON text: "The document root must not be followed by
 *   other values." at position 5
 * - mariadb: SQLSTATE[23000] 4025 CONSTRAINT `api_keys.ip_allowlist` failed
 */
it('refuses every address when the stored allowlist does not parse', function (string $storedAllowlist): void {
    if (DB::getDriverName() !== 'sqlite' && ! json_validate($storedAllowlist)) {
        $this->markTestSkipped('A non-JSON allowlist value is only storable on SQLite.');
    }

    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    DB::table('api_keys')->where('id', $apiKey->getKey())->update(['ip_allowlist' => $storedAllowlist]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertForbidden();
})->with([
    'not json' => '127.0.0.1',
    'a map' => '{"office": "127.0.0.1"}',
    'a non-string entry' => '[127]',
]);

it('enforces the per-key rate limit', function (): void {
    ['plain' => $plain] = adminApiKey(apiKeyData: new ApiKeyData('Slow', [ApiKeyScopeEnum::UsersRead->value], rateLimitPerMinute: 2));

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/users')->assertTooManyRequests();
});

it('refuses a missing scope as insufficient_scope with the scope it needed', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/users', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'password' => 'Password1!Password1!'])
        ->assertForbidden()
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="users:write"')
        ->assertExactJson([
            'message' => 'API key is missing the required scope.',
            'error' => 'insufficient_scope',
            'scope' => 'users:write',
        ]);
});

it('lets write satisfy a read check on the same resource only', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/organizations')
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
});

it('keeps the old secret working through the grace window after a rotation, then refuses it', function (): void {
    ['plain' => $oldToken, 'apiKey' => $apiKey, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->actingAs($admin, 'web')->put(route('admin.api-keys.secret.update', $apiKey->uuid))->assertRedirect();
    $newToken = session('plain_text_api_key');

    expect($newToken)->toBeString()->toStartWith($apiKey->key_prefix)->not->toBe($oldToken);

    $this->withToken($oldToken)->getJson('/api/v1/users')->assertOk();
    $this->withToken($newToken)->getJson('/api/v1/users')->assertOk();

    $this->travel(24)->hours();

    assertInvalidToken($this->withToken($oldToken)->getJson('/api/v1/users'), 'Invalid API key.');
    $this->withToken($newToken)->getJson('/api/v1/users')->assertOk();
});

it('ends the grace window when a rotated key is revoked', function (): void {
    ['plain' => $oldToken, 'apiKey' => $apiKey, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->actingAs($admin, 'web')->put(route('admin.api-keys.secret.update', $apiKey->uuid));
    $this->actingAs($admin, 'web')->delete(route('admin.api-keys.destroy', $apiKey->uuid));

    // The replaced secret no longer matches anything, so it is simply an invalid key.
    expect($apiKey->fresh()?->previous_key_hash)->toBeNull();
    assertInvalidToken($this->withToken($oldToken)->getJson('/api/v1/users'), 'Invalid API key.');
});

it('records when and from where a key was last used, at most once a minute per address', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $this->freezeSecond();

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $firstUse = $apiKey->fresh();

    expect($firstUse?->last_used_at?->equalTo(now()))->toBeTrue()
        ->and($firstUse?->last_used_ip)->toBe('127.0.0.1');

    $this->travel(30)->seconds();
    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();

    expect($apiKey->fresh()?->last_used_at?->equalTo($firstUse?->last_used_at))->toBeTrue();

    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])->withToken($plain)->getJson('/api/v1/users')->assertOk();

    expect($apiKey->fresh()?->last_used_ip)->toBe('192.0.2.10')
        ->and($apiKey->fresh()?->last_used_at?->equalTo(now()))->toBeTrue();
});

it('authenticates on its own guard and never establishes a web session', function (): void {
    ['plain' => $plain, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $response = $this->withToken($plain)->getJson('/api/v1/users')->assertOk();

    expect(Auth::guard('api-key')->user()?->is($admin))->toBeTrue()
        ->and(Auth::guard('web')->user())->toBeNull()
        ->and($response->headers->getCookies())->toBe([]);
});

it('cannot reach key management with an api key', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
    $keyHashBefore = $apiKey->key_hash;

    $this->withoutMiddleware(VerifyCsrfTokenMiddleware::class)->withToken($plain)
        ->postJson(route('admin.api-keys.store'), ['name' => 'Minted', 'scopes' => [ApiKeyScopeEnum::ApplicationsWrite->value]])
        ->assertUnauthorized();

    $this->withoutMiddleware(VerifyCsrfTokenMiddleware::class)->withToken($plain)
        ->putJson(route('admin.api-keys.secret.update', $apiKey->uuid))
        ->assertUnauthorized();

    expect(DB::table('api_keys')->where('name', 'Minted')->exists())->toBeFalse()
        ->and($apiKey->fresh()?->key_hash)->toBe($keyHashBefore);
});

it('has no api route that manages api keys', function (): void {
    $apiKeyRoutes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/') && str_contains($route->uri(), 'key'));

    expect($apiKeyRoutes)->toBeEmpty();
});

it('names the key that authorised an admin api write in the activity log', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/users', ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.com', 'password' => 'Password1!Password1!'])
        ->assertCreated();

    $userCreatedActivity = Activity::query()->where('event', 'created')->where('subject_type', (new User)->getMorphClass())->latest('id')->first();

    expect($userCreatedActivity?->properties?->get('api_key_uuid'))->toBe($apiKey->uuid);
});

it('treats a stored rate limit of zero as the default, never as unlimited', function (): void {
    config(['api.default_rate_limit_per_minute' => 1]);
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    DB::table('api_keys')->where('id', $apiKey->getKey())->update(['rate_limit_per_minute' => 0]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/users')->assertTooManyRequests();
});
