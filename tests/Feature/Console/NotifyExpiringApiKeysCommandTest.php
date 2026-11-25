<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\RotateApiKeyAction;
use App\Data\ApiKeyData;
use App\Data\IssuedApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Models\User;
use App\Notifications\ApiKeyExpiringNotification;
use App\Services\ApiKeyRepositoryService;
use App\Services\Authorization\EffectivePermissionResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/**
 * `api-keys:notify-expiring` (qiick/laravel-api-keys) warns once per key and expiry, as soon as the
 * key expires within `api-keys.expiry.notice_days` (seven), and never carries a secret.
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
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(7)->subHours(12));

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);

    $this->travel(1)->day();
    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);
});

it('also warns the issuer when someone else owns the key, and no other admin', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    $issuer = User::factory()->instanceAdmin()->create();
    $otherAdmin = User::factory()->instanceAdmin()->create();
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(6))->apiKey->forceFill(['created_by' => $issuer->getKey()])->save();

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class);
    Notification::assertSentTo($issuer, ApiKeyExpiringNotification::class);
    Notification::assertNotSentTo($otherAdmin, ApiKeyExpiringNotification::class);
});

it('skips keys that expire later and revoked keys, and warns a key already inside the window', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    $soonKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(3))->apiKey;
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(9));
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(6))->apiKey->forceFill(['revoked_at' => now()])->save();

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);
    Notification::assertSentTo($owner, ApiKeyExpiringNotification::class, static fn (ApiKeyExpiringNotification $notification): bool => $notification->apiKey->is($soonKey));
});

it('names the key by its public prefix and never includes the secret', function (): void {
    $owner = User::factory()->instanceAdmin()->create(['locale' => 'en']);
    $issuedApiKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(6));

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

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
    $issuedApiKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(6));

    $mail = (new ApiKeyExpiringNotification($issuedApiKey->apiKey, $issuedApiKey->apiKey->expires_at->toDateTimeImmutable()))->toMail($owner);

    expect($mail->subject)->toBe(trans('api_keys.expiring.subject', ['name' => 'Nightly sync'], $locale))
        ->and($mail->subject)->not->toContain('api_keys.');
})->with(['en', 'it', 'de']);

it('warns the other instance admins when the issuer is no longer one', function (): void {
    $issuer = User::factory()->instanceAdmin()->create();
    $otherAdmin = User::factory()->instanceAdmin()->create();
    $inactiveAdmin = User::factory()->instanceAdmin()->create(['is_active' => false]);
    $regularUser = User::factory()->create();
    keyExpiringAt($issuer, CarbonImmutable::now()->addDays(6));
    $issuer->syncRoles([]);
    app(EffectivePermissionResolverService::class)->forget();

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertSentToTimes($otherAdmin, ApiKeyExpiringNotification::class, 1);
    Notification::assertNotSentTo([$issuer, $inactiveAdmin, $regularUser], ApiKeyExpiringNotification::class);
});

it('counts the keys it warned about', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(6));
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(5));
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(20));

    $this->artisan('api-keys:notify-expiring')
        ->expectsOutputToContain('Expiry notices sent: 2')
        ->assertSuccessful();
});

it('warns as many days ahead as configured', function (): void {
    config(['api-keys.expiry.notice_days' => 1]);
    $owner = User::factory()->instanceAdmin()->create();
    $tomorrowsKey = keyExpiringAt($owner, CarbonImmutable::now()->addDay()->subHour())->apiKey;
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(3));

    $this->artisan('api-keys:notify-expiring')
        ->expectsOutputToContain('Expiry notices sent: 1')
        ->assertSuccessful();

    Notification::assertSentTo(
        $owner,
        ApiKeyExpiringNotification::class,
        static fn (ApiKeyExpiringNotification $notification): bool => $notification->apiKey->is($tomorrowsKey),
    );
});

// A rotation sets a new expiry, which earns its own notice: RotateApiKeyAction clears the stamp.
it('warns again about the new expiry after a rotation', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    $apiKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(6))->apiKey;

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();
    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);

    app(RotateApiKeyAction::class)->execute($apiKey, $owner, CarbonImmutable::now()->addDays(5));

    expect($apiKey->refresh()->expiry_notified_at)->toBeNull();

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();
    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 2);
});

it('never warns about a key that has already expired', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    keyExpiringAt($owner, CarbonImmutable::now()->addDays(3))->apiKey
        ->forceFill(['expires_at' => CarbonImmutable::now()->subDay()])->save();

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();

    Notification::assertNothingSent();
});

// The notifier claims, sends, then stamps: a rotation in between gives the key a new expiry,
// which the old run's stamp or release must never touch.
it('keeps the notice of the new expiry when a rotation lands while the old one is being sent', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    $apiKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(6))->apiKey;
    $notifier = new ApiKeyRepositoryService;
    $selectedRecord = collect($notifier->expiringUnnotified(CarbonImmutable::now()->addDays(7)->toDateTimeImmutable()))->sole();

    expect($notifier->claimExpiryNotice($selectedRecord, CarbonImmutable::now()->toDateTimeImmutable(), 300))->toBeTrue();

    app(RotateApiKeyAction::class)->execute($apiKey, $owner, CarbonImmutable::now()->addDays(5));
    $notifier->markExpiryNotified($selectedRecord, CarbonImmutable::now()->toDateTimeImmutable());

    expect($apiKey->refresh()->expiry_notified_at)->toBeNull();

    $this->artisan('api-keys:notify-expiring')->assertSuccessful();
    Notification::assertSentToTimes($owner, ApiKeyExpiringNotification::class, 1);
});

it('never lets a stale notifier release a newer claim', function (): void {
    $owner = User::factory()->instanceAdmin()->create();
    $apiKey = keyExpiringAt($owner, CarbonImmutable::now()->addDays(6))->apiKey;
    $staleNotifier = new ApiKeyRepositoryService;
    $currentNotifier = new ApiKeyRepositoryService;
    $staleRecord = collect($staleNotifier->expiringUnnotified(CarbonImmutable::now()->addDays(7)->toDateTimeImmutable()))->sole();

    expect($staleNotifier->claimExpiryNotice($staleRecord, CarbonImmutable::now()->toDateTimeImmutable(), 60))->toBeTrue();

    // The stale claim's lease runs out and another run takes the same expiry over.
    $this->travel(2)->minutes();
    $currentRecord = collect($currentNotifier->expiringUnnotified(CarbonImmutable::now()->addDays(7)->toDateTimeImmutable()))->sole();

    expect($currentNotifier->claimExpiryNotice($currentRecord, CarbonImmutable::now()->toDateTimeImmutable(), 60))->toBeTrue();

    $staleNotifier->releaseExpiryNotice($staleRecord);

    expect($apiKey->refresh()->expiry_notice_claimed_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});
