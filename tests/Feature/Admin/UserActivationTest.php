<?php

declare(strict_types=1);

use App\Jobs\SendBackChannelLogoutJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Admin user activation, 2FA reset and instance-admin flag (#22).
 */
function activationAdmin(array $attributes = []): User
{
    return User::factory()->instanceAdmin()->create($attributes);
}

test('a non admin cannot change activation two factor or instance admin', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->put(route('admin.users.activation.update', $target), ['is_active' => false])
        ->assertForbidden();

    $this->actingAs($actor)
        ->delete(route('admin.users.two-factor.destroy', $target))
        ->assertForbidden();

    $this->actingAs($actor)
        ->put(route('admin.users.instance-admin.update', $target), ['is_instance_admin' => true])
        ->assertForbidden();
});

test('an instance admin deactivates a user revokes tokens and fans out back channel logout', function () {
    Queue::fake();

    $admin = activationAdmin();
    $target = User::factory()->create();
    $client = createOidcClient();
    $client->forceFill([
        'backchannel_logout_uri' => 'https://rp.example.test/backchannel-logout',
        'is_backchannel_logout_session_required' => true,
    ])->save();

    $this->actingAs($target);
    $pkce = pkcePair();
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    redeemCode($client, $code, $pkce['verifier'])->assertOk();

    expect(DB::table('oauth_access_tokens')->where('user_id', $target->getKey())->where('revoked', false)->exists())->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.users.activation.update', $target), ['is_active' => '0'])
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->is_active)->toBeFalse()
        ->and(DB::table('oauth_access_tokens')->where('user_id', $target->getKey())->where('revoked', false)->exists())->toBeFalse();

    Queue::assertPushed(SendBackChannelLogoutJob::class);
});

test('an inactive user cannot sign in with a password', function () {
    $user = User::factory()->inactive()->create([
        'email' => 'off@example.com',
        'password' => 'password',
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('an inactive signed-in user is logged out by the active middleware', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('an instance admin reactivates a user', function () {
    $admin = activationAdmin();
    $target = User::factory()->inactive()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.activation.update', $target), ['is_active' => '1'])
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->is_active)->toBeTrue();
});

test('the last instance admin cannot be deactivated', function () {
    $admin = activationAdmin();

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->put(route('admin.users.activation.update', $admin), ['is_active' => '0'])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasErrors('user');

    expect($admin->refresh()->is_active)->toBeTrue();
});

test('an instance admin resets two factor authentication', function () {
    $admin = activationAdmin();
    $target = User::factory()->withTwoFactor()->create();

    expect($target->hasEnabledTwoFactorAuthentication())->toBeTrue();

    $this->actingAs($admin)
        ->delete(route('admin.users.two-factor.destroy', $target))
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($target->two_factor_secret)->toBeNull();
});

test('an instance admin grants and revokes instance admin when another remains', function () {
    $admin = activationAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.instance-admin.update', $target), ['is_instance_admin' => '1'])
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->is_instance_admin)->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.users.instance-admin.update', $target), ['is_instance_admin' => '0'])
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->is_instance_admin)->toBeFalse();
});

test('the last instance admin flag cannot be revoked', function () {
    $admin = activationAdmin();

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->put(route('admin.users.instance-admin.update', $admin), ['is_instance_admin' => '0'])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasErrors('user');

    expect($admin->refresh()->is_instance_admin)->toBeTrue();
});
