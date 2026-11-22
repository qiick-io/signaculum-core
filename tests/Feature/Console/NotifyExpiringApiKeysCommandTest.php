<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Data\IssuedApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * `signaculum:notify-expiring-api-keys` warns once, seven days ahead, and never carries a secret.
 */
function keyExpiringAt(User $owner, CarbonImmutable $expiresAt): IssuedApiKeyData
{
    return app(CreateApiKeyAction::class)->execute(
        $owner,
        new ApiKeyData('Nightly sync', [ApiKeyScopeEnum::UsersRead->value], expiresAt: $expiresAt),
    );
}

beforeEach(function (): void {
    Notification::fake();
    $this->freezeSecond();
});

it('warns the owner of a key that expires in seven days, once', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(7)->addHours(12));

    $this->artisan('signaculum:notify-expiring-api-keys')->assertSuccessful();

    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);

    $this->travel(1)->day();
    $this->artisan('signaculum:notify-expiring-api-keys')->assertSuccessful();

    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);
});

it('also warns the issuer when someone else owns the key', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    $issuer = User::factory()->instanceAdmin()->create();
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(7)->addHour())->apiKey->forceFill(['created_by' => $issuer->getKey()])->save();

    $this->artisan('signaculum:notify-expiring-api-keys')->assertSuccessful();

    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class);
    Notification::assertSentTo($issuer, ApiKeyExpiringNotification::class);
});

it('skips keys outside the window, revoked keys and keys that never expire', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(3));
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(9));
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(7)->addHour())->apiKey->forceFill(['revoked_at' => now()])->save();
    app(CreateApiKeyAction::class)->execute($owner, new ApiKeyData('Forever', [ApiKeyScopeEnum::UsersRead->value]));

    $this->artisan('signaculum:notify-expiring-api-keys')->assertSuccessful();

    Notification::assertNothingSent();
});

it('names the key by its public prefix and never includes the secret', function (): void {
    $owner = User::factory()->instanceAdmin()->create(['locale' => 'en']);
    $issuedApiKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(7)->addHour());

    $this->artisan('signaculum:notify-expiring-api-keys')->assertSuccessful();

    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class, function (ApiKeyExpiringNotification $notification) use ($owner, $issuedApiKey): bool {
        $mail = $notification->toMail($owner);
        $renderedMail = $mail->subject.' '.implode(' ', $mail->introLines).' '.serialize($notification);

        return str_contains($renderedMail, $issuedApiKey->apiKey->key_prefix)
            && str_contains($renderedMail, 'Nightly sync')
            && ! str_contains($renderedMail, $issuedApiKey->plainTextToken)
            && ! str_contains($renderedMail, $issuedApiKey->apiKey->key_hash);
    });
});

it('writes the warning in the recipient\'s language', function (string $locale): void {
    $owner = User::factory()->instanceAdmin()->create(['locale' => $locale]);
    $issuedApiKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(7)->addHour());

    $mail = (new ApiKeyExpiringNotification($issuedApiKey->apiKey))->toMail($owner);

    expect($mail->subject)->toBe(trans('api_keys.expiring.subject', ['name' => 'Nightly sync'], $locale))
        ->and($mail->subject)->not->toContain('api_keys.');
})->with(['en', 'it', 'de']);
