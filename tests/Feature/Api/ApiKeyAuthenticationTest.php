<?php

declare(strict_types=1);

use App\Actions\RevokeApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Middleware\VerifyCsrfTokenMiddleware;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Qiick\Access\EffectivePermissionResolver;
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
    assertInvalidToken($this->withToken('sk_'.str_repeat('a', 40))->getJson('/api/v1/users'), 'The API key is not valid.');
});

it('answers an unknown key and a wrong secret for a real key the same way', function (): void {
    ['apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $unknownKey = $this->withToken('sig_test_Unknown1'.str_repeat('a', 32))->getJson('/api/v1/users');
    $wrongSecret = $this->withToken($apiKey->key_prefix.str_repeat('a', 32))->getJson('/api/v1/users');

    assertInvalidToken($unknownKey, 'The API key is not valid.');
    assertInvalidToken($wrongSecret, 'The API key is not valid.');
    expect($wrongSecret->getContent())->toBe($unknownKey->getContent());
});

it('refuses an expired key', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $apiKey->forceFill(['expires_at' => now()->subSecond()])->saveQuietly();

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'The API key has expired.');
});

it('refuses a revoked key on the very next request', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();

    app(RevokeApiKeyAction::class)->execute($apiKey, $admin);

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'The API key has been revoked.');
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
it('refuses a key whose stored scopes no longer parse as holding no scopes', function (string $storedScopes): void {
    if (DB::getDriverName() !== 'sqlite' && ! json_validate($storedScopes)) {
        $this->markTestSkipped('A non-JSON scopes value is only storable on SQLite.');
    }

    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    DB::table('api_keys')->where('id', $apiKey->getKey())->update(['scopes' => $storedScopes]);

    // qiick/laravel-api-keys: scopes fail closed as `403 insufficient_scope`, never 401.
    $this->withToken($plain)->getJson('/api/v1/users')
        ->assertForbidden()
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope"')
        ->assertExactJson(['message' => 'The API key holds no scopes.', 'error' => 'insufficient_scope']);
})->with([
    'empty list' => '[]',
    'json null' => 'null',
    'not json' => 'users:read',
    'unknown scope' => '["users:admin"]',
    'known next to unknown' => '["users:read", "keys:write"]',
    'a map' => '{"users": "read"}',
]);

it('keeps a key valid when its creating admin is deactivated if another instance admin remains', function (): void {
    ['plain' => $plain, 'admin' => $creator] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    instanceAdmin();

    $creator->forceFill(['is_active' => false])->save();

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
});

it('keeps a key valid when its creating admin loses permission if another instance admin remains', function (): void {
    ['plain' => $plain, 'admin' => $creator] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    instanceAdmin();

    $creator->syncRoles([]);
    app(EffectivePermissionResolver::class)->forget($creator);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
});

it('refuses a key when no active instance admin remains', function (): void {
    ['plain' => $plain, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $admin->forceFill(['is_active' => false])->save();

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'API key owner may not use the API.');
});

// The owner must be active by qiick/laravel-access' own rule (PrincipalDirectory::isActive()),
// which also ends it at its expiry, not only when it is deactivated.
it('refuses a key whose instance service principal has expired', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    DB::table('principals')->where('id', $apiKey->owner_principal_id)->update(['expires_at' => now()->subMinute()]);

    assertInvalidToken($this->withToken($plain)->getJson('/api/v1/users'), 'API key owner may not use the API.');
});

it('refuses a request from outside the ip allowlist', function (): void {
    ['plain' => $plain] = adminApiKey(apiKeyData: new ApiKeyData('Office only', [ApiKeyScopeEnum::UsersRead->value], expiresAt: apiKeyExpiry(), ipAllowlist: ['10.0.0.0/8']));

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
    // Only SQL NULL means "no allowlist": a JSON null or an object, empty included, denies.
    'a JSON null' => 'null',
    'an empty object' => '{}',
]);

it('lets every address through when no allowlist is stored', function (?string $storedAllowlist): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    DB::table('api_keys')->where('id', $apiKey->getKey())->update(['ip_allowlist' => $storedAllowlist]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
})->with([
    'SQL NULL' => null,
    'an empty list' => '[]',
]);

it('enforces the per-key rate limit', function (): void {
    ['plain' => $plain] = adminApiKey(apiKeyData: new ApiKeyData('Slow', [ApiKeyScopeEnum::UsersRead->value], expiresAt: apiKeyExpiry(), rateLimitPerMinute: 2));

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

    $this->actingAs($admin, 'web')->put(route('admin.api-keys.secret.update', $apiKey->uuid), ['expires_at' => apiKeyExpiryInput()])->assertRedirect();
    $newToken = session('plain_text_api_key');

    expect($newToken)->toBeString()->toStartWith($apiKey->key_prefix)->not->toBe($oldToken);

    $this->withToken($oldToken)->getJson('/api/v1/users')->assertOk();
    $this->withToken($newToken)->getJson('/api/v1/users')->assertOk();

    $this->travel(24)->hours();

    assertInvalidToken($this->withToken($oldToken)->getJson('/api/v1/users'), 'The API key is not valid.');
    $this->withToken($newToken)->getJson('/api/v1/users')->assertOk();
});

it('ends the grace window when a rotated key is revoked', function (): void {
    ['plain' => $oldToken, 'apiKey' => $apiKey, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->actingAs($admin, 'web')->put(route('admin.api-keys.secret.update', $apiKey->uuid), ['expires_at' => apiKeyExpiryInput()]);
    $this->actingAs($admin, 'web')->delete(route('admin.api-keys.destroy', $apiKey->uuid));

    // The replaced secret no longer matches anything, so it is simply an invalid key.
    expect($apiKey->fresh()?->previous_key_hash)->toBeNull();
    assertInvalidToken($this->withToken($oldToken)->getJson('/api/v1/users'), 'The API key is not valid.');
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

    expect($userCreatedActivity?->properties?->get('api_key_id'))->toBe($apiKey->uuid);
});

it('treats a stored rate limit of zero as the default, never as unlimited', function (): void {
    config(['api-keys.default_rate_limit_per_minute' => 1, 'api.address_rate_limit_per_minute' => 100]);
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    DB::table('api_keys')->where('id', $apiKey->getKey())->update(['rate_limit_per_minute' => 0]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/users')
        ->assertTooManyRequests()
        ->assertHeader('X-RateLimit-Limit', '1');
});

it('keeps answering when the default per-key limit is switched off', function (): void {
    config(['api-keys.default_rate_limit_per_minute' => 0]);
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    foreach (range(1, 3) as $request) {
        $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    }
});

it('throttles an address on its own setting, before the key is checked', function (): void {
    config(['api.address_rate_limit_per_minute' => 2, 'api-keys.default_rate_limit_per_minute' => 100]);
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/users')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '2')
        ->assertJsonPath('error', 'rate_limit_exceeded');
});

// The address throttle runs before route-model binding: a lookup of a record that does not exist
// counts like any other request, so guessing ids is throttled before authentication too.
it('counts requests for missing records against the address throttle', function (): void {
    config(['api.address_rate_limit_per_minute' => 2, 'api-keys.default_rate_limit_per_minute' => 100]);
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->withToken($plain)->getJson('/api/v1/users/'.Str::uuid())->assertNotFound();
    $this->withToken($plain)->getJson('/api/v1/users/'.Str::uuid())->assertNotFound();
    $this->withToken($plain)->getJson('/api/v1/users')
        ->assertTooManyRequests()
        ->assertJsonPath('error', 'rate_limit_exceeded');
});

it('switches the address throttle off at zero', function (): void {
    config(['api.address_rate_limit_per_minute' => 0, 'api-keys.default_rate_limit_per_minute' => 100]);
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    foreach (range(1, 3) as $request) {
        $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
    }
});

it('answers 503, never 500, when the rate-limit store is unreachable', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    config([
        'cache.stores.unreachable' => ['driver' => 'database', 'connection' => null, 'table' => 'missing_cache_table', 'lock_table' => 'missing_cache_locks'],
        'cache.default' => 'unreachable',
    ]);
    app()->forgetInstance(RateLimiter::class);
    RateLimiterFacade::clearResolvedInstance(RateLimiter::class);

    $this->withToken($plain)->getJson('/api/v1/users')
        ->assertServiceUnavailable()
        ->assertJsonPath('error', 'service_unavailable');
});

// qiick/laravel-api-keys issues version 2 tokens: the canonical token plus a 6-character
// checksum, in the shape secret scanners match (README, "Token shape").
it('issues checksummed tokens that secret scanners can match', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    expect($plain)->toMatch('/^sig_(?:live|test)_[A-Za-z0-9]{40}[A-Za-z0-9]{6}$/')
        ->and($apiKey->refresh()->token_version)->toBe(2);

    $this->withToken($plain)->getJson('/api/v1/users')->assertOk();
});

// The failed-attempt brake: after `api-keys.failed_attempts_per_minute` wrong guesses from one
// address, even the right key is answered 429, so guessing reveals nothing more in that minute.
it('brakes an address after too many wrong guesses, the right key included', function (): void {
    config(['api-keys.failed_attempts_per_minute' => 3]);
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $wrongKey = substr($plain, 0, -6).'aaaaaa';

    foreach (range(1, 3) as $guess) {
        $this->withToken($wrongKey)->getJson('/api/v1/users')->assertUnauthorized();
    }

    $this->withToken($plain)->getJson('/api/v1/users')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('error', 'rate_limit_exceeded');
});

it('records the user agent of the last use, truncated', function (): void {
    ['plain' => $plain, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $this->withToken($plain)->withHeader('User-Agent', 'nightly-sync/'.str_repeat('x', 600))->getJson('/api/v1/users')->assertOk();

    expect($apiKey->refresh()->last_used_user_agent)->toStartWith('nightly-sync/')
        ->and(mb_strlen((string) $apiKey->last_used_user_agent))->toBe(512);
});
