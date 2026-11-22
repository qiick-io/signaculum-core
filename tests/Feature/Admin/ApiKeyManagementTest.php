<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RevokeApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin → API keys on the server side: the show-once secret, the audit trail of create, rotate
 * and revoke, and who may manage which key.
 */
function apiKeyIssuingAdmin(): User
{
    return User::factory()->instanceAdmin()->create();
}

it('shows the plain-text key once and stores only its hash', function (): void {
    $admin = apiKeyIssuingAdmin();

    $this->actingAs($admin)
        ->post(route('admin.api-keys.store'), ['name' => 'CI key', 'scopes' => [ApiKeyScopeEnum::UsersRead->value]])
        ->assertRedirect(route('admin.api-keys.index'));

    $plainTextToken = session('plain_text_api_key');
    $apiKey = ApiKey::query()->where('name', 'CI key')->sole();

    expect($plainTextToken)->toBeString()->toStartWith($apiKey->key_prefix)
        ->and($apiKey->key_hash)->toBe(hash('sha256', $plainTextToken))
        ->and($apiKey->created_by)->toBe($admin->getKey());

    $this->actingAs($admin)->get(route('admin.api-keys.index'))
        ->assertInertia(fn ($page) => $page->where('plainTextSecret', $plainTextToken));

    $this->actingAs($admin)->get(route('admin.api-keys.index'))
        ->assertInertia(fn ($page) => $page->where('plainTextSecret', null));
});

it('never writes the plain-text key to the key row, its attributes or the activity log', function (): void {
    $admin = apiKeyIssuingAdmin();
    $created = app(CreateApiKeyAction::class)->execute($admin, new ApiKeyData('Leak check', [ApiKeyScopeEnum::UsersRead->value]));
    $rotated = app(RotateApiKeyAction::class)->execute($created->apiKey, $admin);
    app(RevokeApiKeyAction::class)->execute($rotated->apiKey, $admin);

    $storedRow = json_encode(DB::table('api_keys')->where('id', $created->apiKey->getKey())->first(), JSON_THROW_ON_ERROR);
    $activityRows = json_encode(DB::table('activity_log')->get(), JSON_THROW_ON_ERROR);
    $modelAttributes = json_encode($created->apiKey->fresh()?->getAttributes(), JSON_THROW_ON_ERROR);

    foreach ([$created->plainTextToken, $rotated->plainTextToken] as $plainTextToken) {
        expect($storedRow)->not->toContain($plainTextToken)
            ->and($activityRows)->not->toContain($plainTextToken)
            ->and($modelAttributes)->not->toContain($plainTextToken)
            ->and(json_encode($rotated->apiKey->toArray(), JSON_THROW_ON_ERROR))->not->toContain($plainTextToken);
    }

    expect($activityRows)->not->toContain((string) $created->apiKey->fresh()?->key_hash);
});

it('logs create, rotate and revoke in the api-keys log with the actor', function (): void {
    $admin = apiKeyIssuingAdmin();
    $issuedApiKey = app(CreateApiKeyAction::class)->execute($admin, new ApiKeyData('Audited', [ApiKeyScopeEnum::UsersWrite->value]));
    app(RotateApiKeyAction::class)->execute($issuedApiKey->apiKey, $admin);
    app(RevokeApiKeyAction::class)->execute($issuedApiKey->apiKey->fresh() ?? $issuedApiKey->apiKey, $admin);

    $entries = Activity::query()->with(['causer', 'subject'])->where('log_name', 'api-keys')->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['created', 'rotated', 'revoked']);

    foreach ($entries as $entry) {
        expect($entry->causer?->is($admin))->toBeTrue()
            ->and($entry->subject?->is($issuedApiKey->apiKey))->toBeTrue()
            ->and($entry->properties?->get('name'))->toBe('Audited')
            ->and($entry->properties?->get('scopes'))->toBe([ApiKeyScopeEnum::UsersWrite->value]);
    }
});

it('rotates a key in place: same row, prefix, scopes and audit trail', function (): void {
    $admin = apiKeyIssuingAdmin();
    $issuedApiKey = app(CreateApiKeyAction::class)->execute($admin, new ApiKeyData('Rotating', [ApiKeyScopeEnum::UsersRead->value]));
    $before = $issuedApiKey->apiKey->fresh();
    $this->freezeSecond();

    $this->actingAs($admin)->put(route('admin.api-keys.secret.update', $issuedApiKey->apiKey->uuid))
        ->assertRedirect(route('admin.api-keys.index'));

    $after = $issuedApiKey->apiKey->fresh();

    expect(ApiKey::query()->count())->toBe(1)
        ->and($after?->uuid)->toBe($before?->uuid)
        ->and($after?->key_prefix)->toBe($before?->key_prefix)
        ->and($after?->scopes)->toBe($before?->scopes)
        ->and($after?->key_hash)->not->toBe($before?->key_hash)
        ->and($after?->previous_key_hash)->toBe($before?->key_hash)
        ->and($after?->previous_key_valid_until?->equalTo(now()->addHours(24)))->toBeTrue()
        ->and(session('plain_text_api_key'))->toStartWith((string) $before?->key_prefix);
});

it('refuses to rotate a revoked or expired key', function (array $keyState): void {
    $admin = apiKeyIssuingAdmin();
    $issuedApiKey = app(CreateApiKeyAction::class)->execute($admin, new ApiKeyData('Dead', [ApiKeyScopeEnum::UsersRead->value]));
    $issuedApiKey->apiKey->forceFill($keyState)->saveQuietly();
    $keyHashBefore = $issuedApiKey->apiKey->key_hash;

    $this->actingAs($admin)->put(route('admin.api-keys.secret.update', $issuedApiKey->apiKey->uuid))->assertForbidden();

    expect($issuedApiKey->apiKey->fresh()?->key_hash)->toBe($keyHashBefore);
})->with([
    'revoked' => [['revoked_at' => now()->subMinute()]],
    'expired' => [['expires_at' => now()->subMinute()]],
]);

it('lets any instance admin rotate or revoke an instance_service key', function (): void {
    $owner = apiKeyIssuingAdmin();
    $otherAdmin = apiKeyIssuingAdmin();
    $issuedApiKey = app(CreateApiKeyAction::class)->execute($owner, new ApiKeyData('Mine', [ApiKeyScopeEnum::UsersRead->value]));

    $this->actingAs($otherAdmin)->put(route('admin.api-keys.secret.update', $issuedApiKey->apiKey->uuid))
        ->assertRedirect(route('admin.api-keys.index'));

    expect($issuedApiKey->apiKey->fresh()?->isActive())->toBeTrue();

    $this->actingAs($otherAdmin)->delete(route('admin.api-keys.destroy', $issuedApiKey->apiKey->uuid))
        ->assertRedirect(route('admin.api-keys.index'));

    expect($issuedApiKey->apiKey->fresh()?->isRevoked())->toBeTrue();
});

it('refuses key management to users who are not instance admins', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.api-keys.index'))->assertForbidden();
    $this->actingAs($user)
        ->post(route('admin.api-keys.store'), ['name' => 'Sneaky', 'scopes' => [ApiKeyScopeEnum::UsersRead->value]])
        ->assertForbidden();

    expect(ApiKey::query()->count())->toBe(0);
});

it('never issues a key with a scope its issuer does not hold', function (): void {
    $user = User::factory()->create();

    app(CreateApiKeyAction::class)->execute($user, new ApiKeyData('Escalation', [ApiKeyScopeEnum::UsersWrite->value]));
})->throws(AuthorizationException::class);

it('never issues a key with no scope or an unknown scope, even from code', function (array $scopes): void {
    app(CreateApiKeyAction::class)->execute(apiKeyIssuingAdmin(), new ApiKeyData('Broken', $scopes));
})->throws(InvalidArgumentException::class)->with([
    'no scope' => [[]],
    'unknown scope' => [['api-keys:write']],
]);

it('rejects invalid input at the trust boundary', function (array $payload, string $invalidField): void {
    $this->actingAs(apiKeyIssuingAdmin())
        ->post(route('admin.api-keys.store'), [...['name' => 'Key', 'scopes' => [ApiKeyScopeEnum::UsersRead->value]], ...$payload])
        ->assertSessionHasErrors($invalidField);

    expect(ApiKey::query()->count())->toBe(0);
})->with([
    'no scopes' => [['scopes' => []], 'scopes'],
    'unknown scope' => [['scopes' => ['users:admin']], 'scopes.0'],
    'scope outside the vocabulary' => [['scopes' => ['*']], 'scopes.0'],
    'expiry in the past' => [['expires_at' => '2020-01-01'], 'expires_at'],
    'malformed ip' => [['ip_allowlist' => '10.0.0.1, not-an-ip'], 'ip_allowlist'],
    'cidr prefix too long' => [['ip_allowlist' => '10.0.0.0/33'], 'ip_allowlist'],
    'zero rate limit' => [['rate_limit_per_minute' => 0], 'rate_limit_per_minute'],
]);

it('stores one scope per resource and the optional restrictions', function (): void {
    $this->actingAs(apiKeyIssuingAdmin())->post(route('admin.api-keys.store'), [
        'name' => 'Restricted',
        'scopes' => [ApiKeyScopeEnum::UsersRead->value, ApiKeyScopeEnum::UsersWrite->value, ApiKeyScopeEnum::OrganizationsRead->value],
        'expires_at' => now()->addMonth()->toDateTimeString(),
        'ip_allowlist' => "10.0.0.0/8,\n2001:db8::/32",
        'rate_limit_per_minute' => 30,
    ])->assertSessionHasNoErrors();

    $apiKey = ApiKey::query()->sole();

    expect($apiKey->scopes)->toBe([ApiKeyScopeEnum::UsersWrite->value, ApiKeyScopeEnum::OrganizationsRead->value])
        ->and($apiKey->ip_allowlist)->toBe(['10.0.0.0/8', '2001:db8::/32'])
        ->and($apiKey->rate_limit_per_minute)->toBe(30)
        ->and($apiKey->expires_at?->isFuture())->toBeTrue();
});

it('revokes a key and keeps the row', function (): void {
    $admin = apiKeyIssuingAdmin();
    $issuedApiKey = app(CreateApiKeyAction::class)->execute($admin, new ApiKeyData('Gone', [ApiKeyScopeEnum::UsersRead->value]));

    $this->actingAs($admin)->delete(route('admin.api-keys.destroy', $issuedApiKey->apiKey->uuid))
        ->assertRedirect(route('admin.api-keys.index'));

    expect($issuedApiKey->apiKey->fresh()?->revoked_at)->not->toBeNull();

    $this->actingAs($admin)->delete(route('admin.api-keys.destroy', $issuedApiKey->apiKey->uuid));

    expect(Activity::query()->where('log_name', 'api-keys')->where('event', 'revoked')->count())->toBe(1);
});

it('answers 404 for an api key route key that is not a uuid', function (): void {
    $admin = apiKeyIssuingAdmin();

    $this->actingAs($admin)
        ->delete(route('admin.api-keys.destroy', 'not-a-uuid'))
        ->assertNotFound();
});
