<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\DeactivateUserAction;
use App\Actions\ReactivateUserAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\PrincipalTypeEnum;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: every user has a principal; API keys name their owner
 * principal; deactivating a user deactivates that principal (#192 / #211).
 */
test('principals table exists with the fleet shape', function (): void {
    expect(Schema::hasTable('principals'))->toBeTrue()
        ->and(Schema::hasColumns('principals', [
            'id',
            'type',
            'user_id',
            'organisation_id',
            'name',
            'deactivated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('api_keys', 'owner_principal_id'))->toBeTrue();
});

test('creating a user automatically creates a user principal', function (): void {
    $user = User::factory()->create(['first_name' => 'Auto', 'last_name' => 'Principal']);

    $principal = Principal::query()->where('user_id', $user->getKey())->first();

    expect($principal)->not->toBeNull()
        ->and($principal?->type)->toBe(PrincipalTypeEnum::User)
        ->and($principal?->name)->toBe('Auto Principal')
        ->and($principal?->deactivated_at)->toBeNull();
});

test('creating an api key writes owner_principal_id from the owner user', function (): void {
    $admin = User::factory()->instanceAdmin()->create();

    $issued = app(CreateApiKeyAction::class)->execute(
        $admin,
        new ApiKeyData('Principal key', [ApiKeyScopeEnum::UsersRead->value]),
    );

    $principal = Principal::query()->where('user_id', $admin->getKey())->firstOrFail();

    expect($issued->apiKey->owner_principal_id)->toBe($principal->id)
        ->and(ApiKey::query()->whereKey($issued->apiKey->getKey())->value('owner_principal_id'))
        ->toBe($principal->id);
});

test('deactivating a user also sets principals.deactivated_at', function (): void {
    User::factory()->instanceAdmin()->create();
    $user = User::factory()->create();
    $principal = Principal::query()->where('user_id', $user->getKey())->firstOrFail();

    expect($principal->deactivated_at)->toBeNull();

    app(DeactivateUserAction::class)->execute($user);

    expect($user->refresh()->is_active)->toBeFalse()
        ->and($principal->refresh()->deactivated_at)->not->toBeNull();
});

test('reactivating a user clears principals.deactivated_at', function (): void {
    User::factory()->instanceAdmin()->create();
    $user = User::factory()->create();
    $principal = Principal::query()->where('user_id', $user->getKey())->firstOrFail();

    app(DeactivateUserAction::class)->execute($user);

    expect($principal->refresh()->deactivated_at)->not->toBeNull();

    app(ReactivateUserAction::class)->execute($user->refresh());

    expect($user->refresh()->is_active)->toBeTrue()
        ->and($principal->refresh()->deactivated_at)->toBeNull();
});
