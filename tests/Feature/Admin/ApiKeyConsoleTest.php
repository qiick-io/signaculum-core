<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Admin → API keys as the console page receives it: every listed field, newest key first, and
 * the confirmation each action shows.
 */
beforeEach(function (): void {
    $this->freezeSecond();
    $this->admin = User::factory()->instanceAdmin()->create();
});

function consoleKey(User $admin, string $name): ApiKey
{
    return app(CreateApiKeyAction::class)
        ->execute($admin, new ApiKeyData($name, [ApiKeyScopeEnum::UsersRead->value], expiresAt: apiKeyExpiry(), ipAllowlist: ['10.0.0.0/8']))
        ->apiKey;
}

it('lists every field of each key, newest first', function (): void {
    $oldestKey = consoleKey($this->admin, 'Oldest');
    $this->travel(1)->hour();
    $sameSecondFirst = consoleKey($this->admin, 'Same second, first');
    $sameSecondSecond = consoleKey($this->admin, 'Same second, second');
    $sameSecondSecond->forceFill([
        'last_used_at' => now(),
        'last_used_ip' => '10.1.2.3',
        'previous_key_valid_until' => now()->addDay(),
        'revoked_at' => now(),
    ])->save();

    $this->actingAs($this->admin)->get(route('admin.api-keys.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/api-keys/index')
            ->where('availableScopes', ApiKeyScopeEnum::values())
            ->where('plainTextSecret', null)
            ->where('apiKeys', [
                [
                    'uuid' => $sameSecondSecond->uuid,
                    'name' => 'Same second, second',
                    'key_prefix' => $sameSecondSecond->key_prefix,
                    'scopes' => [ApiKeyScopeEnum::UsersRead->value],
                    'is_active' => false,
                    'expires_at' => apiKeyExpiry()->toIso8601String(),
                    'last_used_at' => now()->toIso8601String(),
                    'last_used_ip' => '10.1.2.3',
                    'previous_key_valid_until' => now()->addDay()->toIso8601String(),
                    'revoked_at' => now()->toIso8601String(),
                    'created_at' => now()->toIso8601String(),
                ],
                [
                    'uuid' => $sameSecondFirst->uuid,
                    'name' => 'Same second, first',
                    'key_prefix' => $sameSecondFirst->key_prefix,
                    'scopes' => [ApiKeyScopeEnum::UsersRead->value],
                    'is_active' => true,
                    'expires_at' => apiKeyExpiry()->toIso8601String(),
                    'last_used_at' => null,
                    'last_used_ip' => null,
                    'previous_key_valid_until' => null,
                    'revoked_at' => null,
                    'created_at' => now()->toIso8601String(),
                ],
                [
                    'uuid' => $oldestKey->uuid,
                    'name' => 'Oldest',
                    'key_prefix' => $oldestKey->key_prefix,
                    'scopes' => [ApiKeyScopeEnum::UsersRead->value],
                    'is_active' => true,
                    'expires_at' => $oldestKey->expires_at->toIso8601String(),
                    'last_used_at' => null,
                    'last_used_ip' => null,
                    'previous_key_valid_until' => null,
                    'revoked_at' => null,
                    'created_at' => now()->subHour()->toIso8601String(),
                ],
            ]));
});

it('confirms creating, rotating and revoking a key', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.api-keys.store'), ['name' => 'Toast', 'scopes' => [ApiKeyScopeEnum::UsersRead->value], 'expires_at' => apiKeyExpiryInput()])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'API key created.']);

    $apiKey = ApiKey::query()->sole();

    $this->actingAs($this->admin)
        ->put(route('admin.api-keys.secret.update', $apiKey->uuid), ['expires_at' => apiKeyExpiryInput()])
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'API key rotated.']);

    $this->actingAs($this->admin)
        ->delete(route('admin.api-keys.destroy', $apiKey->uuid))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'API key revoked.']);
});

it('refuses to revoke a key for a user who is not an instance admin', function (): void {
    $apiKey = consoleKey($this->admin, 'Kept');

    $this->actingAs(User::factory()->create())
        ->delete(route('admin.api-keys.destroy', $apiKey->uuid))
        ->assertForbidden();

    expect($apiKey->fresh()?->isRevoked())->toBeFalse();
});
