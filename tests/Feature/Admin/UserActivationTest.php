<?php

declare(strict_types=1);

use App\Actions\DeactivateUserAction;
use App\Jobs\SendBackChannelLogoutJob;
use App\Models\ClientSession;
use App\Models\OidcSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    expect($target->refresh()->isSuperAdmin())->toBeTrue();

    $this->actingAs($admin)
        ->put(route('admin.users.instance-admin.update', $target), ['is_instance_admin' => '0'])
        ->assertRedirect(route('admin.users.index'));

    expect($target->refresh()->isSuperAdmin())->toBeFalse();
});

test('the last instance admin flag cannot be revoked', function () {
    $admin = activationAdmin();

    $this->actingAs($admin)
        ->from(route('admin.users.index'))
        ->put(route('admin.users.instance-admin.update', $admin), ['is_instance_admin' => '0'])
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasErrors('user');

    expect($admin->refresh()->isSuperAdmin())->toBeTrue();
});

test('deactivation ends every browser session, remember token and OIDC session of the user', function () {
    $this->freezeSecond();
    $admin = activationAdmin();
    $target = User::factory()->create(['remember_token' => 'remembered-token']);
    $bystander = User::factory()->create();

    foreach ([[$target, 'target-session'], [$bystander, 'bystander-session']] as [$owner, $sessionId]) {
        DB::table(config('session.table'))->insert([
            'id' => $sessionId,
            'user_id' => $owner->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest',
            'payload' => '',
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    foreach (['sid-open' => null, 'sid-ended' => now()->subHour()] as $oidcSessionId => $endedAt) {
        OidcSession::query()->create([
            'user_id' => $target->getKey(),
            'laravel_session_id' => "laravel-{$oidcSessionId}",
            'oidc_session_id' => $oidcSessionId,
            'ended_at' => $endedAt,
        ]);
    }

    Queue::fake();
    $client = createOidcClient();
    $client->forceFill(['backchannel_logout_uri' => 'https://rp.example.test/backchannel-logout'])->save();

    // One client session with an OidcSession row, one without (it never wrote one).
    foreach (['sid-open', 'sid-orphan'] as $oidcSessionId) {
        ClientSession::query()->create([
            'user_id' => $target->getKey(),
            'client_id' => $client->getKey(),
            'oidc_session_id' => $oidcSessionId,
        ]);
    }

    app(DeactivateUserAction::class)->execute($target);

    $rememberToken = DB::table('users')->where('id', $target->getKey())->value('remember_token');

    expect(DB::table(config('session.table'))->pluck('id')->all())->toBe(['bystander-session'])
        ->and($rememberToken)->toBeString()->toHaveLength(60)->not->toBe('remembered-token')
        ->and(OidcSession::query()->where('user_id', $target->getKey())->whereNull('ended_at')->exists())->toBeFalse()
        ->and(OidcSession::query()->where('oidc_session_id', 'sid-ended')->sole()->ended_at?->equalTo(now()->subHour()))->toBeTrue()
        ->and($admin->fresh()?->is_active)->toBeTrue();

    foreach (['sid-open', 'sid-orphan'] as $oidcSessionId) {
        Queue::assertPushed(
            SendBackChannelLogoutJob::class,
            fn (SendBackChannelLogoutJob $job): bool => $job->oidcSessionId === $oidcSessionId && $job->subject === $target->subject,
        );
    }

    Queue::assertPushed(SendBackChannelLogoutJob::class, 2);
});

/**
 * Two browsers (`sid-1`, `sid-2`), each signed in to two relying parties with a back-channel URI.
 */
function deactivationTargetWithTwoBrowsers(): User
{
    $target = User::factory()->create();

    foreach (['a', 'b'] as $name) {
        $client = createOidcClient();
        $client->forceFill(['backchannel_logout_uri' => "https://rp-{$name}.example.test/backchannel-logout"])->save();

        foreach (['sid-1', 'sid-2'] as $oidcSessionId) {
            ClientSession::query()->create([
                'user_id' => $target->getKey(),
                'client_id' => $client->getKey(),
                'oidc_session_id' => $oidcSessionId,
            ]);
        }
    }

    foreach (['sid-1', 'sid-2'] as $oidcSessionId) {
        OidcSession::query()->create([
            'user_id' => $target->getKey(),
            'laravel_session_id' => "laravel-{$oidcSessionId}",
            'oidc_session_id' => $oidcSessionId,
        ]);
    }

    return $target;
}

test('a deactivation that fails half way changes nothing and notifies no relying party, so it can be retried', function () {
    syncPermissions();
    Http::fake(['*.example.test/*' => Http::response('', 200)]);
    $target = deactivationTargetWithTwoBrowsers();
    $isFailing = true;

    ClientSession::saving(function (ClientSession $clientSession) use (&$isFailing): void {
        if ($isFailing && $clientSession->oidc_session_id === 'sid-2' && $clientSession->ended_at !== null) {
            throw new RuntimeException('Simulated failure while closing a client session.');
        }
    });

    expect(fn () => app(DeactivateUserAction::class)->execute($target))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
    expect($target->fresh()?->is_active)->toBeTrue()
        ->and(ClientSession::query()->whereNull('ended_at')->count())->toBe(4)
        ->and(OidcSession::query()->whereNull('ended_at')->count())->toBe(2);

    $isFailing = false;
    app(DeactivateUserAction::class)->execute($target->fresh());

    Http::assertSentCount(4);
    expect($target->fresh()?->is_active)->toBeFalse()
        ->and(ClientSession::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(OidcSession::query()->whereNull('ended_at')->count())->toBe(0);
});

test('on the sync queue a relying party that fails its logout does not keep the others from theirs', function (Closure $failingResponse) {
    syncPermissions();
    config(['queue.default' => 'sync', 'oidc.backchannel_logout_tries' => 3]);
    Http::fake([
        'rp-a.example.test/*' => $failingResponse,
        'rp-b.example.test/*' => Http::response('', 200),
    ]);
    $target = deactivationTargetWithTwoBrowsers();

    app(DeactivateUserAction::class)->execute($target);

    $sentHosts = Http::recorded()->map(fn (array $exchange): string => parse_url($exchange[0]->url(), PHP_URL_HOST))->countBy()->all();

    // Two browser sessions on each relying party; the failing one is tried three times each.
    expect($sentHosts)->toBe(['rp-a.example.test' => 6, 'rp-b.example.test' => 2])
        ->and($target->fresh()?->is_active)->toBeFalse()
        ->and(ClientSession::query()->whereNull('ended_at')->count())->toBe(0);
})->with([
    'an error status' => fn () => fn () => Http::response('', 500),
    'an unreachable host' => fn () => fn () => Http::failedConnection(),
]);
