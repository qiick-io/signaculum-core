<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;

function adminApiKey(array $scopes = [ApiKeyScopeEnum::UsersRead->value, ApiKeyScopeEnum::UsersWrite->value]): array
{
    $admin = User::factory()->create(['is_instance_admin' => true]);
    $apiKey = app(CreateApiKeyAction::class)->execute($admin, 'Test key', $scopes);

    return [
        'admin' => $admin,
        'plain' => (string) $apiKey->getAttribute('plain_secret'),
        'apiKey' => $apiKey,
    ];
}

it('serves the openapi document without a key', function (): void {
    $this->getJson('/api/v1/openapi.json')
        ->assertOk()
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('info.title', 'Signaculum Admin API');
});

it('lists users with a scoped api key', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    User::factory()->create(['email' => 'listed@example.com']);

    $this->withToken($plain)
        ->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('refuses users list without the read scope', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);

    $this->withToken($plain)
        ->getJson('/api/v1/users')
        ->assertForbidden();
});

it('creates a user through the admin api', function (): void {
    ['plain' => $plain] = adminApiKey();

    $this->withToken($plain)
        ->postJson('/api/v1/users', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada-api@example.com',
            'password' => 'Password1!Password1!',
        ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada-api@example.com');

    expect(User::query()->where('email', 'ada-api@example.com')->exists())->toBeTrue();
});

it('rejects missing bearer tokens', function (): void {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

it('lets an instance admin create and revoke an api key in the ui', function (): void {
    $admin = User::factory()->create(['is_instance_admin' => true]);

    $this->actingAs($admin)
        ->post(route('admin.api-keys.store'), [
            'name' => 'CI key',
            'scopes' => [ApiKeyScopeEnum::UsersRead->value],
        ])
        ->assertRedirect(route('admin.api-keys.index'));

    $uuid = ApiKey::query()->where('name', 'CI key')->value('uuid');

    expect($uuid)->not->toBeNull();

    $this->actingAs($admin)
        ->delete(route('admin.api-keys.destroy', $uuid))
        ->assertRedirect(route('admin.api-keys.index'));

    expect(ApiKey::query()->where('uuid', $uuid)->value('revoked_at'))->not->toBeNull();
});
