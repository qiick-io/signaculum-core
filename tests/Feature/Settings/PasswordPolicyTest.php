<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Granular instance password policy (#119): min length, character classes, and optional
 * Have I Been Pwned check (off by default; k-anonymity hash prefix only).
 */
function passwordPolicyAdmin(): User
{
    return User::factory()->create(['is_instance_admin' => true]);
}

function granularSecurityPayload(array $overrides = []): array
{
    return [
        'password_min_length' => 10,
        'password_require_mixed_case' => '1',
        'password_require_numbers' => '1',
        'password_require_symbols' => '0',
        'password_reject_compromised' => '0',
        'login_max_attempts' => 5,
        'login_lockout_threshold' => 5,
        'login_lockout_minutes' => 15,
        'registration_enabled' => '0',
        'two_factor_required' => '0',
        'email_code_login_enabled' => '0',
        ...$overrides,
    ];
}

it('defaults to a local-only policy with breached-password checks off', function () {
    $settings = app(SettingsRepositoryService::class);

    expect($settings->passwordMinLength())->toBe(8)
        ->and($settings->requiresPasswordMixedCase())->toBeFalse()
        ->and($settings->requiresPasswordNumbers())->toBeFalse()
        ->and($settings->requiresPasswordSymbols())->toBeFalse()
        ->and($settings->rejectsCompromisedPasswords())->toBeFalse();

    expect(validator(['password' => 'password'], ['password' => Password::default()])->passes())->toBeTrue();
});

it('shows the granular password policy fields to instance admins', function () {
    $this->actingAs(passwordPolicyAdmin())
        ->get(route('instance.security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/instance/security')
            ->where('instance.password_min_length', 8)
            ->where('instance.password_require_mixed_case', false)
            ->where('instance.password_require_numbers', false)
            ->where('instance.password_require_symbols', false)
            ->where('instance.password_reject_compromised', false)
            ->missing('passwordPolicies'));
});

it('saves the granular password policy and enforces it on Password::default', function () {
    $this->actingAs(passwordPolicyAdmin())
        ->put(route('instance.security.update'), granularSecurityPayload())
        ->assertRedirect(route('instance.security.edit'));

    $settings = app(SettingsRepositoryService::class);

    expect($settings->passwordMinLength())->toBe(10)
        ->and($settings->requiresPasswordMixedCase())->toBeTrue()
        ->and($settings->requiresPasswordNumbers())->toBeTrue()
        ->and($settings->requiresPasswordSymbols())->toBeFalse()
        ->and($settings->rejectsCompromisedPasswords())->toBeFalse();

    expect(validator(['password' => 'alllowercase1'], ['password' => Password::default()])->fails())->toBeTrue()
        ->and(validator(['password' => 'MixedCase1'], ['password' => Password::default()])->passes())->toBeTrue()
        ->and(validator(['password' => 'Short1Aa'], ['password' => Password::default()])->fails())->toBeTrue();
});

it('rejects known breached passwords only when the opt-in is enabled', function () {
    app(SettingsRepositoryService::class)->save([
        'password_min_length' => 8,
        'password_require_mixed_case' => false,
        'password_require_numbers' => false,
        'password_require_symbols' => false,
        'password_reject_compromised' => false,
    ]);

    expect(validator(['password' => 'password'], ['password' => Password::default()])->passes())->toBeTrue();

    app(SettingsRepositoryService::class)->save(['password_reject_compromised' => true]);

    $candidate = 'Password1!';
    $prefix = strtoupper(substr(sha1($candidate), 0, 5));
    $suffix = strtoupper(substr(sha1($candidate), 5));

    // NotPwnedVerifier is a singleton that keeps the Http factory from first resolve;
    // rebind after faking so the range request hits the fake, not the network.
    Http::fake([
        "api.pwnedpasswords.com/range/{$prefix}" => Http::response("{$suffix}:99\n", 200),
    ]);
    app()->forgetInstance(UncompromisedVerifier::class);

    expect(validator(['password' => $candidate], ['password' => Password::default()])->fails())->toBeTrue();
});

it('applies the instance password policy when an admin creates a user', function () {
    app(SettingsRepositoryService::class)->save([
        'password_min_length' => 12,
        'password_require_mixed_case' => true,
        'password_require_numbers' => true,
        'password_require_symbols' => true,
        'password_reject_compromised' => false,
    ]);
    Password::defaults(fn (): Password => app(SettingsRepositoryService::class)->passwordRule());

    $this->actingAs(passwordPolicyAdmin())
        ->post(route('admin.users.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
        ])
        ->assertSessionHasErrors('password');

    $this->actingAs(passwordPolicyAdmin())
        ->post(route('admin.users.store'), [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
            'password' => 'GoodPass1!xx',
        ])
        ->assertRedirect();

    expect(User::query()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('applies the instance password policy when a user changes their password', function () {
    app(SettingsRepositoryService::class)->save([
        'password_min_length' => 12,
        'password_require_mixed_case' => true,
        'password_require_numbers' => true,
        'password_require_symbols' => false,
        'password_reject_compromised' => false,
    ]);
    Password::defaults(fn (): Password => app(SettingsRepositoryService::class)->passwordRule());

    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'tooshort1',
            'password_confirmation' => 'tooshort1',
        ])
        ->assertSessionHasErrors('password');

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'LongEnough1a',
            'password_confirmation' => 'LongEnough1a',
        ])
        ->assertRedirect();

    expect(Hash::check('LongEnough1a', $user->refresh()->password))->toBeTrue();
});

it('validates the granular password policy fields', function () {
    $this->actingAs(passwordPolicyAdmin())
        ->put(route('instance.security.update'), granularSecurityPayload([
            'password_min_length' => 3,
            'password_require_mixed_case' => 'maybe',
            'password_reject_compromised' => 'maybe',
        ]))
        ->assertSessionHasErrors([
            'password_min_length',
            'password_require_mixed_case',
            'password_reject_compromised',
        ]);
});
