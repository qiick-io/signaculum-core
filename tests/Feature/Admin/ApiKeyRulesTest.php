<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\DeleteUserAction;
use App\Actions\RotateApiKeyAction;
use App\Actions\SetInstanceAdminAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Events\ApiKeyCreatorDeletedEvent;
use App\Models\ApiKey;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/**
 * The rules of Admin API keys one by one, so that each one is proven by a test that fails
 * without it: what the creation form accepts, what a stored key allows, and what rotation,
 * revocation on deletion and the super-admin role change leave behind.
 */
beforeEach(function (): void {
    $this->freezeSecond();
    $this->admin = User::factory()->instanceAdmin()->create();
});

function issuedRuleKey(User $admin, ?ApiKeyData $apiKeyData = null): ApiKey
{
    return app(CreateApiKeyAction::class)
        ->execute($admin, $apiKeyData ?? new ApiKeyData('Rule key', [ApiKeyScopeEnum::UsersWrite->value], expiresAt: apiKeyExpiry()))
        ->apiKey;
}

describe('the creation form', function (): void {
    it('refuses a field that breaks its rule, with that rule\'s message', function (array $payload, string $field, string $message): void {
        $this->actingAs($this->admin)
            ->postJson(route('admin.api-keys.store'), [
                ...['name' => 'Key', 'scopes' => [ApiKeyScopeEnum::UsersRead->value], 'expires_at' => apiKeyExpiryInput()],
                ...$payload,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        expect(ApiKey::query()->count())->toBe(0);
    })->with([
        'no name' => [['name' => null], 'name', 'The name field is required.'],
        'a name that is not text' => [['name' => ['Key']], 'name', 'The name field must be a string.'],
        'a name too long' => [['name' => str_repeat('k', 256)], 'name', 'The name field must not be greater than 255 characters.'],
        'no scopes' => [['scopes' => null], 'scopes', 'The scopes field is required.'],
        'an empty scope list' => [['scopes' => []], 'scopes', 'The scopes field is required.'],
        'scopes that are not a list' => [['scopes' => 'users:read'], 'scopes', 'The scopes field must be an array.'],
        'an empty scope' => [['scopes' => [null]], 'scopes.0', 'The scopes.0 field is required.'],
        'a scope that is not text' => [['scopes' => [123]], 'scopes.0', 'The scopes.0 field must be a string.'],
        'a repeated scope' => [['scopes' => ['users:read', 'users:read']], 'scopes.0', 'The scopes.0 field has a duplicate value.'],
        'an unknown scope' => [['scopes' => ['users:admin']], 'scopes.0', 'The selected scopes.0 is invalid.'],
        'an allowlist that is not text' => [['ip_allowlist' => ['10.0.0.1']], 'ip_allowlist', 'The ip allowlist field must be a string.'],
        'an allowlist too long' => [['ip_allowlist' => str_repeat('1', 2001)], 'ip_allowlist', 'The ip allowlist field must not be greater than 2000 characters.'],
        'a rate limit that is not a number' => [['rate_limit_per_minute' => 'fast'], 'rate_limit_per_minute', 'The rate limit per minute field must be an integer.'],
        'a rate limit too high' => [['rate_limit_per_minute' => 100001], 'rate_limit_per_minute', 'The rate limit per minute field must not be greater than 100000.'],
    ]);

    it('refuses an allowlist entry that is not an address or a range that fits it', function (string $entry): void {
        $this->actingAs($this->admin)
            ->postJson(route('admin.api-keys.store'), [
                'name' => 'Key',
                'scopes' => [ApiKeyScopeEnum::UsersRead->value],
                'expires_at' => apiKeyExpiryInput(),
                'ip_allowlist' => "10.0.0.1, {$entry}",
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ip_allowlist' => "\"{$entry}\" is not an IP address or CIDR range."]);
    })->with([
        'not an address' => 'intranet',
        'two prefixes' => '10.0.0.0/8/9',
        'a negative prefix' => '10.0.0.0/-1',
        'an IPv4 prefix past 32' => '10.0.0.0/33',
        // qiick/laravel-api-keys maps an IPv4-mapped range onto IPv4 only from /96 up.
        'an IPv4-mapped range below /96' => '::ffff:10.0.0.0/64',
        'an IPv6 prefix past 128' => '2001:db8::/129',
        'a prefix that is not a number' => '10.0.0.0/eight',
    ]);

    it('stores each allowlist entry once, in order, with every valid prefix length', function (): void {
        $this->actingAs($this->admin)
            ->post(route('admin.api-keys.store'), [
                'name' => 'Office',
                'scopes' => [ApiKeyScopeEnum::UsersRead->value],
                'expires_at' => apiKeyExpiryInput(),
                'ip_allowlist' => "10.0.0.1, 2001:db8::/128,\n10.0.0.1 10.0.0.0/32, 10.0.0.0/0, 2001:db8::/0",
                'rate_limit_per_minute' => '30',
            ])
            ->assertSessionHasNoErrors();

        $apiKey = ApiKey::query()->sole();

        expect($apiKey->ip_allowlist)->toBe(['10.0.0.1', '2001:db8::/128', '10.0.0.0/32', '10.0.0.0/0', '2001:db8::/0'])
            ->and($apiKey->rate_limit_per_minute)->toBe(30);
    });

    it('leaves the optional restrictions empty when they are sent empty', function (): void {
        $this->actingAs($this->admin)
            ->postJson(route('admin.api-keys.store'), [
                'name' => 'Open',
                'scopes' => [ApiKeyScopeEnum::UsersRead->value],
                'expires_at' => apiKeyExpiryInput(),
                'ip_allowlist' => null,
                'rate_limit_per_minute' => null,
            ])
            ->assertRedirect(route('admin.api-keys.index'));

        $apiKey = ApiKey::query()->sole();

        expect($apiKey->ip_allowlist)->toBeNull()
            ->and($apiKey->rate_limit_per_minute)->toBeNull();
    });
});

describe('a stored key', function (): void {
    it('allows the scopes it holds, write implying read, given as an enum or as text', function (): void {
        $apiKey = issuedRuleKey($this->admin);

        expect($apiKey->hasScope(ApiKeyScopeEnum::UsersWrite))->toBeTrue()
            ->and($apiKey->hasScope('users:read'))->toBeTrue()
            ->and($apiKey->hasScope(ApiKeyScopeEnum::OrganizationsRead))->toBeFalse()
            ->and($apiKey->hasScope('organizations:read'))->toBeFalse();

        $apiKey->forceFill(['scopes' => ['users:admin']]);

        expect($apiKey->hasScope('users:read'))->toBeFalse()
            ->and($apiKey->hasScope(ApiKeyScopeEnum::UsersRead))->toBeFalse();
    });

    it('reads its IP allowlist strictly', function (mixed $storedAllowlist, bool $isAllowed): void {
        $apiKey = issuedRuleKey($this->admin);
        DB::table('api_keys')->where('id', $apiKey->getKey())->update([
            'ip_allowlist' => $storedAllowlist === null ? null : json_encode($storedAllowlist, JSON_THROW_ON_ERROR),
        ]);

        expect($apiKey->fresh()?->allowsIp('10.1.2.3'))->toBe($isAllowed);
    })->with([
        'no allowlist' => [null, true],
        'an empty allowlist' => [[], true],
        'a matching range' => [['192.0.2.1', '10.0.0.0/8'], true],
        'no matching entry' => [['192.0.2.0/24'], false],
        'an entry that is not text' => [['10.0.0.0/8', 10], false],
        'a map instead of a list' => [['office' => '10.0.0.0/8'], false],
        'not a list at all' => ['10.0.0.0/8', false],
    ]);

    it('keeps both hashes out of its array form and out of the activity log', function (): void {
        $apiKey = issuedRuleKey($this->admin);
        $firstHash = $apiKey->key_hash;
        $rotatedApiKey = app(RotateApiKeyAction::class)->execute($apiKey, $this->admin, apiKeyExpiry())->apiKey->fresh();
        $activityRows = json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR);

        expect($rotatedApiKey?->previous_key_hash)->toBe($firstHash)
            ->and($rotatedApiKey?->toArray())->not->toHaveKeys(['key_hash', 'previous_key_hash'])
            ->and($activityRows)->not->toContain($firstHash)
            ->and($activityRows)->not->toContain((string) $rotatedApiKey?->key_hash);
    });

    it('casts its stored values', function (): void {
        $apiKey = issuedRuleKey($this->admin);
        $apiKey->forceFill(['rate_limit_per_minute' => '45', 'revoked_at' => now()->toDateTimeString()]);

        expect($apiKey->rate_limit_per_minute)->toBe(45)
            ->and($apiKey->revoked_at)->toBeInstanceOf(CarbonInterface::class)
            ->and($apiKey->expires_at)->toBeInstanceOf(CarbonInterface::class);
    });

    it('can never be filled from a payload', function (): void {
        expect(fn () => (new ApiKey)->fill(['name' => 'Mass assigned']))->toThrow(MassAssignmentException::class);
    });
});

describe('rotation', function (): void {
    it('checks the actor before anything else', function (): void {
        $apiKey = issuedRuleKey($this->admin);

        app(RotateApiKeyAction::class)->execute($apiKey, User::factory()->create(), now()->toImmutable()->addYear());
    })->throws(AuthorizationException::class);

    it('re-checks the key under the lock, so a key revoked meanwhile is not rotated', function (): void {
        $apiKey = issuedRuleKey($this->admin);
        $keyHashBefore = $apiKey->key_hash;
        DB::table('api_keys')->where('id', $apiKey->getKey())->update(['revoked_at' => now()]);

        expect(fn () => app(RotateApiKeyAction::class)->execute($apiKey, $this->admin, apiKeyExpiry()))
            ->toThrow(AuthorizationException::class)
            ->and(DB::table('api_keys')->where('id', $apiKey->getKey())->value('key_hash'))->toBe($keyHashBefore);
    });

    it('records the grace window and the new expiry, also when there is no grace window', function (int $graceHours, ?string $expectedGraceEnd): void {
        config(['api-keys.rotation_grace_hours' => $graceHours]);
        $apiKey = issuedRuleKey($this->admin);

        app(RotateApiKeyAction::class)->execute($apiKey, $this->admin, apiKeyExpiry());

        $rotation = Activity::query()->where('log_name', 'api-keys')->where('event', 'rotated')->sole();

        expect($rotation->properties?->get('previous_key_valid_until'))->toBe($expectedGraceEnd === null ? null : now()->addHours($graceHours)->toIso8601String())
            ->and($rotation->properties?->get('expires_at'))->toBe(apiKeyExpiry()->toIso8601String())
            ->and($rotation->properties?->get('name'))->toBe('Rule key');
    })->with([
        'a day of grace' => [24, 'set'],
        'no grace' => [0, null],
    ]);
});

describe('deleting the creator', function (): void {
    it('revokes their keys completely and logs each revocation', function (): void {
        User::factory()->instanceAdmin()->create();
        $apiKey = issuedRuleKey($this->admin);
        app(RotateApiKeyAction::class)->execute($apiKey, $this->admin, apiKeyExpiry());

        app(DeleteUserAction::class)->execute($this->admin);

        $revoked = $apiKey->fresh();
        $revocation = Activity::query()->where('log_name', 'api-keys')->where('event', 'revoked')->sole();

        expect($revoked?->isRevoked())->toBeTrue()
            ->and($revoked?->previous_key_hash)->toBeNull()
            ->and($revoked?->previous_key_valid_until)->toBeNull()
            ->and($revocation->subject?->is($apiKey))->toBeTrue()
            ->and((string) $revocation->causer_id)->toBe((string) $this->admin->getKey())
            // MySQL stores JSON object keys in its own order, so compare them sorted.
            ->and($revocation->properties?->only(['name', 'scopes', 'reason'])->sortKeys()->all())->toBe([
                'name' => 'Rule key',
                'reason' => 'user_deleted',
                'scopes' => [ApiKeyScopeEnum::UsersWrite->value],
            ]);
    });

    it('announces the working keys as a list, even after an expired one', function (): void {
        User::factory()->instanceAdmin()->create();
        Event::fake([ApiKeyCreatorDeletedEvent::class]);
        issuedRuleKey($this->admin)->forceFill(['expires_at' => now()->subDay()])->save();
        $workingKey = issuedRuleKey($this->admin);

        app(DeleteUserAction::class)->execute($this->admin);

        Event::assertDispatched(
            ApiKeyCreatorDeletedEvent::class,
            static fn (ApiKeyCreatorDeletedEvent $event): bool => $event->revokedApiKeyIds === [$workingKey->getKey()],
        );
    });
});

it('lets a super-admin grant the role to the last super-admin again', function (): void {
    app(SetInstanceAdminAction::class)->execute($this->admin, true, $this->admin);

    expect($this->admin->fresh()?->hasRole('super-admin'))->toBeTrue();
});
