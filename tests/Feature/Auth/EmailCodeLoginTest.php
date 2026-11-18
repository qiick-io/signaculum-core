<?php

declare(strict_types=1);

use App\Enums\AuthenticationMethodEnum;
use App\Models\EmailLoginCode;
use App\Models\User;
use App\Notifications\EmailLoginCodeNotification;
use App\Services\AuthenticationContextService;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    app(SettingsRepositoryService::class)->save(['email_code_login_enabled' => true]);
});

test('email code login is hidden when the instance setting is off', function () {
    app(SettingsRepositoryService::class)->save(['email_code_login_enabled' => false]);

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEmailCodeLogin', false));

    $this->post(route('login.email-code.store'), ['email' => 'a@example.com'])
        ->assertForbidden();
});

test('a signed-in user receives a one-time email code that logs them in with amr otp only', function () {
    Notification::fake();
    config(['oidc.issuer' => 'https://id.example.test']);

    $user = User::factory()->create(['email' => 'otp@example.test']);

    $this->post(route('login.email-code.store'), ['email' => 'otp@example.test'])
        ->assertRedirect(route('login.email-code.show', ['email' => 'otp@example.test']));

    $plainCode = '';
    Notification::assertSentTo(
        $user,
        EmailLoginCodeNotification::class,
        function (EmailLoginCodeNotification $notification) use (&$plainCode): bool {
            $plainCode = $notification->plainCode;

            return strlen($plainCode) === 6;
        },
    );

    $row = EmailLoginCode::query()->where('email', 'otp@example.test')->latest('id')->firstOrFail();

    $this->post(route('login.email-code.verify'), [
        'email' => 'otp@example.test',
        'code' => $plainCode,
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    expect(app(AuthenticationContextService::class)->methodsFor($user->getKey()))
        ->toBe([AuthenticationMethodEnum::OneTimePassword->value]);

    expect($row->refresh()->consumed_at)->not->toBeNull();

    $this->post(route('logout'));

    $this->post(route('login.email-code.verify'), [
        'email' => 'otp@example.test',
        'code' => $plainCode,
    ])->assertSessionHasErrors('code');
});

test('expired and wrong codes are refused', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'expire@example.test']);

    $this->post(route('login.email-code.store'), ['email' => 'expire@example.test']);

    $row = EmailLoginCode::query()->where('email', 'expire@example.test')->firstOrFail();
    $row->forceFill([
        'code_hash' => Hash::make('654321'),
        'expires_at' => Date::now()->subMinute(),
    ])->save();

    $this->post(route('login.email-code.verify'), [
        'email' => 'expire@example.test',
        'code' => '654321',
    ])->assertSessionHasErrors('code');

    $this->assertGuest();

    $row->forceFill([
        'expires_at' => Date::now()->addMinutes(10),
        'consumed_at' => null,
    ])->save();

    $this->post(route('login.email-code.verify'), [
        'email' => 'expire@example.test',
        'code' => '000000',
    ])->assertSessionHasErrors('code');
});

test('locked accounts get the generic send response and still cannot redeem a code', function () {
    Notification::fake();

    User::factory()->create([
        'email' => 'locked@example.test',
        'locked_until' => Date::now()->addHour(),
    ]);

    $this->post(route('login.email-code.store'), ['email' => 'locked@example.test'])
        ->assertRedirect(route('login.email-code.show', ['email' => 'locked@example.test']))
        ->assertSessionHas('status')
        ->assertSessionDoesntHaveErrors();

    Notification::assertNothingSent();
    expect(EmailLoginCode::query()->count())->toBe(0);

    EmailLoginCode::query()->create([
        'email' => 'locked@example.test',
        'code_hash' => Hash::make('111111'),
        'expires_at' => Date::now()->addMinutes(10),
    ]);

    $this->post(route('login.email-code.verify'), [
        'email' => 'locked@example.test',
        'code' => '111111',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('unknown emails do not reveal whether the account exists', function () {
    Notification::fake();

    $this->post(route('login.email-code.store'), ['email' => 'missing@example.test'])
        ->assertRedirect(route('login.email-code.show', ['email' => 'missing@example.test']));

    Notification::assertNothingSent();
    expect(EmailLoginCode::query()->count())->toBe(0);
});

test('a user with two-factor enabled is challenged after a valid email code', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    Notification::fake();

    $google2fa = app(Google2FA::class);
    $secret = $google2fa->generateSecretKey();
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'mfa-otp@example.test',
        'two_factor_secret' => encrypt($secret),
    ]);

    $this->post(route('login.email-code.store'), ['email' => 'mfa-otp@example.test']);

    $plainCode = '';
    Notification::assertSentTo(
        $user,
        EmailLoginCodeNotification::class,
        function (EmailLoginCodeNotification $notification) use (&$plainCode): bool {
            $plainCode = $notification->plainCode;

            return true;
        },
    );

    $this->post(route('login.email-code.verify'), [
        'email' => 'mfa-otp@example.test',
        'code' => $plainCode,
    ])
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);

    $this->assertGuest();

    expect(app(AuthenticationContextService::class)->methodsFor($user->getKey()))
        ->toBe([AuthenticationMethodEnum::OneTimePassword->value]);

    $this->post('/two-factor-challenge', [
        'code' => $google2fa->getCurrentOtp($secret),
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);

    expect(app(AuthenticationContextService::class)->methodsFor($user->getKey()))
        ->toBe([
            AuthenticationMethodEnum::OneTimePassword->value,
            AuthenticationMethodEnum::MultipleFactors->value,
        ]);
});

test('a successful email code login regenerates the session id', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'session@example.test']);

    $this->get(route('login.email-code.show', ['email' => 'session@example.test']));
    $sessionIdBefore = session()->getId();

    $this->post(route('login.email-code.store'), ['email' => 'session@example.test']);

    $plainCode = '';
    Notification::assertSentTo(
        $user,
        EmailLoginCodeNotification::class,
        function (EmailLoginCodeNotification $notification) use (&$plainCode): bool {
            $plainCode = $notification->plainCode;

            return true;
        },
    );

    $this->post(route('login.email-code.verify'), [
        'email' => 'session@example.test',
        'code' => $plainCode,
    ])->assertRedirect(route('dashboard'));

    expect(session()->getId())->not->toBe($sessionIdBefore);
    $this->assertAuthenticatedAs($user);
});

test('expired and consumed email login codes are pruned', function () {
    EmailLoginCode::query()->create([
        'email' => 'old@example.test',
        'code_hash' => Hash::make('111111'),
        'expires_at' => Date::now()->subHour(),
    ]);

    EmailLoginCode::query()->create([
        'email' => 'used@example.test',
        'code_hash' => Hash::make('222222'),
        'expires_at' => Date::now()->addMinutes(10),
        'consumed_at' => Date::now()->subMinute(),
    ]);

    $keep = EmailLoginCode::query()->create([
        'email' => 'keep@example.test',
        'code_hash' => Hash::make('333333'),
        'expires_at' => Date::now()->addMinutes(10),
    ]);

    $this->artisan('model:prune', ['--model' => EmailLoginCode::class])
        ->assertSuccessful();

    expect(EmailLoginCode::query()->pluck('id')->all())->toBe([$keep->id]);
});

test('an instance admin can enable email code login from security settings', function () {
    app(SettingsRepositoryService::class)->save(['email_code_login_enabled' => false]);

    $admin = User::factory()->create(['is_instance_admin' => true]);

    $this->actingAs($admin)
        ->put(route('instance.security.update'), securitySettingsPayload([
            'email_code_login_enabled' => '1',
        ]))
        ->assertRedirect(route('instance.security.edit'));

    expect(app(SettingsRepositoryService::class)->isEmailCodeLoginEnabled())->toBeTrue();
});
