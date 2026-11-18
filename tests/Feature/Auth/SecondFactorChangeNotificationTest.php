<?php

declare(strict_types=1);

use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Notifications\SecondFactorChangedNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Passkey;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

/**
 * A password alone can manage second factors (#168), so every change mails the account owner
 * and writes an `auth` activity entry: TOTP enable / confirm / disable, recovery-code
 * regeneration, passkey registration and deletion, and the admin TOTP reset.
 */
beforeEach(function () {
    Notification::fake();

    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    Features::passkeys(['confirmPassword' => true]);

    $this->user = User::factory()->create(['email' => 'owner@example.test']);
});

function storedPasskeyFor(User $user, string $name = 'Office key'): Passkey
{
    $passkey = new Passkey([
        'name' => $name,
        'credential_id' => 'credential-'.$name,
        'credential' => ['transports' => ['usb'], 'aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);
    $passkey->user_id = $user->getKey();
    $passkey->save();

    return $passkey;
}

function assertSecondFactorChangeRecorded(User $user, ActivityEventEnum $secondFactorChange, ?User $causer = null): Activity
{
    Notification::assertSentTo(
        $user,
        SecondFactorChangedNotification::class,
        fn (SecondFactorChangedNotification $notification): bool => $notification->secondFactorChange === $secondFactorChange,
    );

    $activity = Activity::query()->where('event', $secondFactorChange->value)->sole();

    expect($activity->log_name)->toBe('auth')
        ->and($activity->subject_type)->toBe($user->getMorphClass())
        ->and((string) $activity->subject_id)->toBe((string) $user->getKey())
        ->and((string) $activity->causer_id)->toBe((string) ($causer ?? $user)->getKey());

    return $activity;
}

test('enabling, confirming and disabling TOTP each mail the owner and log an event', function () {
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('two-factor.enable'))->assertRedirect();
    assertSecondFactorChangeRecorded($this->user, ActivityEventEnum::TwoFactorEnabled);

    $secret = decrypt($this->user->refresh()->two_factor_secret);
    $this->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect();
    assertSecondFactorChangeRecorded($this->user, ActivityEventEnum::TwoFactorConfirmed);

    $this->delete(route('two-factor.disable'))->assertRedirect();
    assertSecondFactorChangeRecorded($this->user, ActivityEventEnum::TwoFactorDisabled);

    Notification::assertSentToTimes($this->user, SecondFactorChangedNotification::class, 3);
});

test('regenerating recovery codes mails the owner and logs an event', function () {
    $user = User::factory()->withTwoFactor()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertRedirect();

    assertSecondFactorChangeRecorded($user, ActivityEventEnum::RecoveryCodesRegenerated);
});

test('registering a passkey mails the owner and logs its id and name', function () {
    $passkey = storedPasskeyFor($this->user, 'New laptop');

    $this->actingAs($this->user);
    PasskeyRegistered::dispatch($this->user, $passkey);

    $activity = assertSecondFactorChangeRecorded($this->user, ActivityEventEnum::PasskeyRegistered);

    expect($activity->properties->only(['passkey_id', 'passkey_name'])->all())->toBe([
        'passkey_id' => $passkey->getKey(),
        'passkey_name' => 'New laptop',
    ]);
});

test('deleting a passkey mails the owner and logs an event', function () {
    $passkey = storedPasskeyFor($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $passkey));

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();

    assertSecondFactorChangeRecorded($this->user, ActivityEventEnum::PasskeyDeleted);
});

test('an admin two-factor reset mails the owner and names the admin as the causer', function () {
    $user = User::factory()->withTwoFactor()->create();
    $admin = instanceAdmin();

    $this->actingAs($admin)
        ->delete(route('admin.users.two-factor.destroy', $user))
        ->assertRedirect(route('admin.users.index'));

    assertSecondFactorChangeRecorded($user, ActivityEventEnum::TwoFactorDisabled, causer: $admin);
    Notification::assertNotSentTo($admin, SecondFactorChangedNotification::class);
});

test('an admin API two-factor reset names the API key owner as the causer', function () {
    $user = User::factory()->withTwoFactor()->create();
    ['admin' => $admin, 'plain' => $plainApiKey] = adminApiKey();

    $this->withToken($plainApiKey)
        ->postJson('/api/v1/users/'.$user->getKey().'/reset-two-factor')
        ->assertOk();

    assertSecondFactorChangeRecorded($user, ActivityEventEnum::TwoFactorDisabled, causer: $admin);
});

test('the alert mail names the change and the account in the owner locale', function (string $locale, string $expectedChange) {
    $this->user->forceFill(['locale' => $locale])->save();

    $mail = (new SecondFactorChangedNotification(ActivityEventEnum::PasskeyRegistered))->toMail($this->user);
    $renderedMail = (string) $mail->render();

    expect($mail->subject)->not->toBeEmpty()
        ->and($mail->introLines)->toContain($expectedChange)
        ->and($mail->actionUrl)->toBe(route('security.edit'))
        ->and($renderedMail)->toContain('owner@example.test');
})->with([
    'en' => ['en', 'A passkey was added.'],
    'it' => ['it', 'È stata aggiunta una passkey.'],
    'de' => ['de', 'Ein Passkey wurde hinzugefügt.'],
]);

test('every second-factor change has its mail line in each locale', function (string $locale) {
    foreach ([
        ActivityEventEnum::TwoFactorEnabled,
        ActivityEventEnum::TwoFactorConfirmed,
        ActivityEventEnum::TwoFactorDisabled,
        ActivityEventEnum::RecoveryCodesRegenerated,
        ActivityEventEnum::PasskeyRegistered,
        ActivityEventEnum::PasskeyDeleted,
    ] as $secondFactorChange) {
        $translationKey = 'second_factors.changes.'.$secondFactorChange->value;

        expect(__($translationKey, [], $locale))->not->toBe($translationKey);
    }
})->with(['en', 'it', 'de']);
