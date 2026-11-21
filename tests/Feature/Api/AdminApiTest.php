<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

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

it('shows a user when authorized with view', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $user = User::factory()->create(['email' => 'shown@example.com']);

    $abilities = [];
    Gate::before(function ($actor, string $ability) use (&$abilities) {
        $abilities[] = $ability;

        return null;
    });

    $this->withToken($plain)
        ->getJson('/api/v1/users/'.$user->subject)
        ->assertOk()
        ->assertJsonPath('data.email', 'shown@example.com');

    expect($abilities)->toContain('view')->not->toContain('update');
});

it('refuses users list without the read scope', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::ApplicationsRead->value]);

    $this->withToken($plain)
        ->getJson('/api/v1/users')
        ->assertForbidden()
        ->assertJsonPath('error', 'insufficient_scope');
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
    $admin = User::factory()->instanceAdmin()->create();

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
