<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Http\Requests\Admin\UpdateApiKeySecretRequest;
use App\Http\Requests\Concerns\ValidatesApiKeyExpiryTrait;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyLifetimeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

mutates(ApiKeyLifetimeService::class, UpdateApiKeySecretRequest::class, ValidatesApiKeyExpiryTrait::class);

/**
 * Every Admin API key expires, at most `api.key_max_lifetime_days` (90) after its creation or its
 * last rotation (decision 2026-11-22 on the instance_service principal). The console form and a
 * JSON client get a validation error (422); code calling the actions directly gets an exception.
 */
beforeEach(function (): void {
    $this->freezeSecond();
    $this->admin = User::factory()->instanceAdmin()->create();
});

/**
 * @return array<string, mixed>
 */
function apiKeyCreationPayload(array $overrides = []): array
{
    return [...['name' => 'Expiring key', 'scopes' => [ApiKeyScopeEnum::UsersRead->value]], ...$overrides];
}

function issuedKeyToRotate(User $admin): ApiKey
{
    return app(CreateApiKeyAction::class)
        ->execute($admin, new ApiKeyData('Rotating', [ApiKeyScopeEnum::UsersRead->value], expiresAt: apiKeyExpiry()))
        ->apiKey;
}

it('refuses a key without an expiry', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('admin.api-keys.store'), apiKeyCreationPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expires_at' => 'Every API key needs an expiry date.']);

    $this->actingAs($this->admin)
        ->post(route('admin.api-keys.store'), apiKeyCreationPayload(['expires_at' => '']))
        ->assertSessionHasErrors('expires_at');

    expect(ApiKey::query()->count())->toBe(0);
});

it('refuses a key that expires after the maximum lifetime', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('admin.api-keys.store'), apiKeyCreationPayload([
            'expires_at' => now()->addDays(90)->addSecond()->toDateTimeString(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expires_at' => 'An API key may last at most 90 days.']);

    $this->actingAs($this->admin)
        ->post(route('admin.api-keys.store'), apiKeyCreationPayload(['expires_at' => now()->addYear()->toDateString()]))
        ->assertSessionHasErrors('expires_at');

    expect(ApiKey::query()->count())->toBe(0);
});

it('accepts a key that expires exactly at the maximum lifetime', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.api-keys.store'), apiKeyCreationPayload(['expires_at' => now()->addDays(90)->toDateTimeString()]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.api-keys.index'));

    expect(ApiKey::query()->sole()->expires_at?->equalTo(now()->addDays(90)))->toBeTrue();
});

it('accepts the latest date the console form proposes', function (): void {
    $this->travelTo(now()->setTime(23, 59, 59));

    $this->actingAs($this->admin)->get(route('admin.api-keys.index'))
        ->assertInertia(fn ($page) => $page->where('latestExpiryDate', now()->addDays(90)->toDateString()));

    $this->actingAs($this->admin)
        ->post(route('admin.api-keys.store'), apiKeyCreationPayload(['expires_at' => now()->addDays(90)->toDateString()]))
        ->assertSessionHasNoErrors();

    expect(ApiKey::query()->count())->toBe(1);
});

it('reads the maximum lifetime from configuration', function (): void {
    config(['api.key_max_lifetime_days' => 30]);

    $this->actingAs($this->admin)
        ->postJson(route('admin.api-keys.store'), apiKeyCreationPayload(['expires_at' => now()->addDays(31)->toDateTimeString()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expires_at' => 'An API key may last at most 30 days.']);

    $this->actingAs($this->admin)
        ->postJson(route('admin.api-keys.store'), apiKeyCreationPayload(['expires_at' => now()->addDays(30)->toDateTimeString()]))
        ->assertRedirect(route('admin.api-keys.index'));
});

it('refuses an expiry outside the lifetime even when called from code', function (CarbonImmutable $expiresAt): void {
    app(CreateApiKeyAction::class)->execute(
        $this->admin,
        new ApiKeyData('From code', [ApiKeyScopeEnum::UsersRead->value], expiresAt: $expiresAt),
    );
})->throws(InvalidArgumentException::class)->with([
    'past the maximum' => fn (): CarbonImmutable => CarbonImmutable::now()->addDays(90)->addSecond(),
    'now' => fn (): CarbonImmutable => CarbonImmutable::now(),
    'in the past' => fn (): CarbonImmutable => CarbonImmutable::now()->subDay(),
]);

it('sets the expiry chosen at rotation, up to the maximum counted from the rotation', function (): void {
    $apiKey = issuedKeyToRotate($this->admin);
    $this->travel(20)->days();

    $this->actingAs($this->admin)
        ->put(route('admin.api-keys.secret.update', $apiKey->uuid), ['expires_at' => now()->addDays(90)->toDateTimeString()])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.api-keys.index'));

    expect($apiKey->fresh()?->expires_at?->equalTo(now()->addDays(90)))->toBeTrue();
});

it('refuses a rotation without an expiry or past the maximum', function (array $payload, string $message): void {
    $apiKey = issuedKeyToRotate($this->admin);
    $keyHashBefore = $apiKey->key_hash;

    $this->actingAs($this->admin)
        ->putJson(route('admin.api-keys.secret.update', $apiKey->uuid), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expires_at' => $message]);

    expect($apiKey->fresh()?->key_hash)->toBe($keyHashBefore)
        ->and($apiKey->fresh()?->expires_at?->equalTo(apiKeyExpiry()))->toBeTrue();
})->with([
    'no expiry' => [[], 'Every API key needs an expiry date.'],
    'past the maximum' => [fn (): array => ['expires_at' => now()->addDays(90)->addSecond()->toDateTimeString()], 'An API key may last at most 90 days.'],
]);

it('refuses a rotation past the maximum even when called from code', function (): void {
    $apiKey = issuedKeyToRotate($this->admin);

    app(RotateApiKeyAction::class)->execute($apiKey, $this->admin, CarbonImmutable::now()->addDays(91));
})->throws(InvalidArgumentException::class, 'An API key may last at most 90 days.');

it('treats a non-positive configured lifetime as one day', function (): void {
    config(['api.key_max_lifetime_days' => 0]);
    $lifetime = app(ApiKeyLifetimeService::class);

    expect($lifetime->maximumLifetimeDays())->toBe(1)
        ->and($lifetime->latestExpiryFor(CarbonImmutable::now())->equalTo(now()->addDay()))->toBeTrue();
});

it('refuses an expiry that is not a date', function (): void {
    $this->actingAs($this->admin)
        ->postJson(route('admin.api-keys.store'), apiKeyCreationPayload(['expires_at' => 'next quarter']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expires_at' => 'The expires at field must be a valid date.']);
});

it('defaults to a 90-day lifetime and accepts a lifetime configured as text', function (): void {
    $lifetime = app(ApiKeyLifetimeService::class);
    config(['api' => Arr::except(config('api'), 'key_max_lifetime_days')]);

    expect($lifetime->maximumLifetimeDays())->toBe(90);

    config(['api.key_max_lifetime_days' => '30']);

    expect($lifetime->maximumLifetimeDays())->toBe(30);
});
