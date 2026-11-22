<?php

declare(strict_types=1);

use App\Actions\DispatchBackChannelLogoutAction;
use App\Enums\OrganizationRoleEnum;
use App\Jobs\EndOrganizationMemberOidcSessionsJob;
use App\Jobs\SendBackChannelLogoutJob;
use App\Models\ClientSession;
use App\Models\OidcSession;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\OidcSessionService;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/**
 * Turning on organisation MFA (#174 option b) ends members' OIDC sessions and fans out
 * back-channel logout after the #173 token revocation. Front-channel cannot run without
 * each member's browser; Signaculum's own web session for the acting owner stays intact.
 */
beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->organization = createOrganizationWithOwner($this->owner, ['slug' => 'mfa-logout-org']);
    OrganizationMembership::query()->create([
        'organization_id' => $this->organization->getKey(),
        'user_id' => $this->member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->clientWithBackchannel = createOidcClient();
    $this->clientWithBackchannel->forceFill([
        'backchannel_logout_uri' => 'https://rp.example.test/backchannel-logout',
        'is_backchannel_logout_session_required' => true,
    ])->save();

    $this->clientWithoutBackchannel = createOidcClient();
    $this->clientWithoutBackchannel->forceFill([
        'backchannel_logout_uri' => null,
    ])->save();
});

/**
 * Record an open OIDC browser session and a client session for `$user`.
 *
 * @return array{oidc_session_id: string, laravel_session_id: string}
 */
function recordOpenOidcClientSession(User $user, string $clientId, ?string $oidcSessionId = null): array
{
    $resolvedOidcSessionId = $oidcSessionId ?? 'sid-'.bin2hex(random_bytes(8));
    $laravelSessionId = 'laravel-'.$resolvedOidcSessionId;

    OidcSession::query()->create([
        'user_id' => $user->getKey(),
        'laravel_session_id' => $laravelSessionId,
        'oidc_session_id' => $resolvedOidcSessionId,
    ]);

    ClientSession::query()->create([
        'user_id' => $user->getKey(),
        'client_id' => $clientId,
        'oidc_session_id' => $resolvedOidcSessionId,
    ]);

    return [
        'oidc_session_id' => $resolvedOidcSessionId,
        'laravel_session_id' => $laravelSessionId,
    ];
}

function turnOnOrganizationMfa(User $owner, Organization $organization): void
{
    test()->actingAs($owner)
        ->put(route('organizations.update', $organization), [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'is_mfa_required' => '1',
        ])
        ->assertRedirect(route('organizations.settings', $organization));
}

/**
 * Run every queued EndOrganizationMemberOidcSessionsJob so nested SendBackChannelLogoutJob
 * pushes are visible under Queue::fake().
 */
function processQueuedMemberSessionJobs(): void
{
    $jobs = Queue::pushed(EndOrganizationMemberOidcSessionsJob::class);

    foreach ($jobs as $job) {
        $job->handle(
            app(DispatchBackChannelLogoutAction::class),
            app(OidcSessionService::class),
        );
    }
}

test('turning on MFA ends only that organisations members oidc sessions and fans out logout tokens', function () {
    Queue::fake();

    $memberSid = recordOpenOidcClientSession($this->member, $this->clientWithBackchannel->getKey());
    $ownerSid = recordOpenOidcClientSession($this->owner, $this->clientWithBackchannel->getKey());
    recordOpenOidcClientSession($this->member, $this->clientWithoutBackchannel->getKey());

    $otherOwner = User::factory()->create();
    $otherMember = User::factory()->create();
    $otherOrganization = createOrganizationWithOwner($otherOwner, ['slug' => 'other-mfa-org']);
    OrganizationMembership::query()->create([
        'organization_id' => $otherOrganization->getKey(),
        'user_id' => $otherMember->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);
    $otherMemberSid = recordOpenOidcClientSession($otherMember, $this->clientWithBackchannel->getKey());

    $outsider = User::factory()->create();
    $outsiderSid = recordOpenOidcClientSession($outsider, $this->clientWithBackchannel->getKey());

    turnOnOrganizationMfa($this->owner, $this->organization);

    Queue::assertPushed(EndOrganizationMemberOidcSessionsJob::class, 2);
    Queue::assertPushed(
        EndOrganizationMemberOidcSessionsJob::class,
        fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === (int) $this->member->getKey(),
    );
    Queue::assertPushed(
        EndOrganizationMemberOidcSessionsJob::class,
        fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === (int) $this->owner->getKey(),
    );

    processQueuedMemberSessionJobs();

    expect(OidcSession::query()->where('oidc_session_id', $memberSid['oidc_session_id'])->value('ended_at'))
        ->not->toBeNull()
        ->and(OidcSession::query()->where('oidc_session_id', $ownerSid['oidc_session_id'])->value('ended_at'))
        ->not->toBeNull()
        ->and(OidcSession::query()->where('oidc_session_id', $otherMemberSid['oidc_session_id'])->value('ended_at'))
        ->toBeNull()
        ->and(OidcSession::query()->where('oidc_session_id', $outsiderSid['oidc_session_id'])->value('ended_at'))
        ->toBeNull();

    Queue::assertPushed(SendBackChannelLogoutJob::class, 2);
    Queue::assertPushed(
        SendBackChannelLogoutJob::class,
        fn (SendBackChannelLogoutJob $job): bool => $job->clientId === $this->clientWithBackchannel->getKey()
            && $job->oidcSessionId === $memberSid['oidc_session_id']
            && $job->subject === $this->member->subject,
    );
    Queue::assertPushed(
        SendBackChannelLogoutJob::class,
        fn (SendBackChannelLogoutJob $job): bool => $job->clientId === $this->clientWithBackchannel->getKey()
            && $job->oidcSessionId === $ownerSid['oidc_session_id']
            && $job->subject === $this->owner->subject,
    );
    Queue::assertNotPushed(
        SendBackChannelLogoutJob::class,
        fn (SendBackChannelLogoutJob $job): bool => $job->oidcSessionId === $otherMemberSid['oidc_session_id']
            || $job->oidcSessionId === $outsiderSid['oidc_session_id'],
    );
});

test('a client without a back-channel uri receives no logout_token when MFA is turned on', function () {
    Queue::fake();

    $withUri = recordOpenOidcClientSession($this->member, $this->clientWithBackchannel->getKey());
    $withoutUri = recordOpenOidcClientSession($this->member, $this->clientWithoutBackchannel->getKey());

    turnOnOrganizationMfa($this->owner, $this->organization);
    processQueuedMemberSessionJobs();

    Queue::assertPushed(SendBackChannelLogoutJob::class, 1);
    Queue::assertPushed(
        SendBackChannelLogoutJob::class,
        fn (SendBackChannelLogoutJob $job): bool => $job->oidcSessionId === $withUri['oidc_session_id'],
    );
    Queue::assertNotPushed(
        SendBackChannelLogoutJob::class,
        fn (SendBackChannelLogoutJob $job): bool => $job->oidcSessionId === $withoutUri['oidc_session_id'],
    );

    expect(ClientSession::query()->where('oidc_session_id', $withoutUri['oidc_session_id'])->value('ended_at'))
        ->not->toBeNull();
});

test('true to true and true to false dispatch no member session jobs', function (bool $isMfaRequiredBefore, bool $isMfaRequiredAfter) {
    Queue::fake();

    recordOpenOidcClientSession($this->member, $this->clientWithBackchannel->getKey());
    $this->organization->forceFill(['is_mfa_required' => $isMfaRequiredBefore])->save();

    $this->actingAs($this->owner)
        ->put(route('organizations.update', $this->organization), [
            'name' => $this->organization->name,
            'slug' => $this->organization->slug,
            'is_mfa_required' => $isMfaRequiredAfter ? '1' : '0',
        ])
        ->assertRedirect();

    Queue::assertNotPushed(EndOrganizationMemberOidcSessionsJob::class);
    Queue::assertNotPushed(SendBackChannelLogoutJob::class);
    expect(Activity::query()->where('event', 'organization_member_backchannel_logout_dispatched')->exists())->toBeFalse();
})->with([
    'on stays on' => [true, true],
    'on is turned off' => [true, false],
]);

test('the member session job is idempotent on retry', function () {
    Queue::fake();

    $session = recordOpenOidcClientSession($this->member, $this->clientWithBackchannel->getKey());

    $job = new EndOrganizationMemberOidcSessionsJob((int) $this->member->getKey());
    $dispatchBackChannelLogoutAction = app(DispatchBackChannelLogoutAction::class);
    $oidcSessionService = app(OidcSessionService::class);

    $job->handle($dispatchBackChannelLogoutAction, $oidcSessionService);
    $job->handle($dispatchBackChannelLogoutAction, $oidcSessionService);

    Queue::assertPushed(SendBackChannelLogoutJob::class, 1);
    Queue::assertPushed(
        SendBackChannelLogoutJob::class,
        fn (SendBackChannelLogoutJob $pushedJob): bool => $pushedJob->oidcSessionId === $session['oidc_session_id'],
    );
});

test('turning on MFA leaves the acting owners Signaculum browser session intact', function () {
    Queue::fake();

    $this->actingAs($this->owner);
    session([OidcSessionService::SESSION_KEY => 'sid-owner-browser']);

    OidcSession::query()->create([
        'user_id' => $this->owner->getKey(),
        'laravel_session_id' => session()->getId(),
        'oidc_session_id' => 'sid-owner-browser',
    ]);

    ClientSession::query()->create([
        'user_id' => $this->owner->getKey(),
        'client_id' => $this->clientWithBackchannel->getKey(),
        'oidc_session_id' => 'sid-owner-browser',
    ]);

    turnOnOrganizationMfa($this->owner, $this->organization);
    processQueuedMemberSessionJobs();

    // RP sid is ended for back-channel fan-out; the web Auth session must survive so the
    // authorize gate can step the owner up without forcing a full Signaculum re-login.
    $this->assertAuthenticatedAs($this->owner);
    expect(session(OidcSessionService::SESSION_KEY))->toBe('sid-owner-browser')
        ->and(OidcSession::query()->where('oidc_session_id', 'sid-owner-browser')->value('ended_at'))
        ->not->toBeNull();
});

test('turning on MFA logs the back-channel fan-out with member and logout token counts', function () {
    Queue::fake();

    recordOpenOidcClientSession($this->member, $this->clientWithBackchannel->getKey());
    recordOpenOidcClientSession($this->owner, $this->clientWithBackchannel->getKey());
    recordOpenOidcClientSession($this->member, $this->clientWithoutBackchannel->getKey());

    turnOnOrganizationMfa($this->owner, $this->organization);

    $activity = Activity::query()
        ->where('event', 'organization_member_backchannel_logout_dispatched')
        ->sole();

    expect($activity->log_name)->toBe('organizations')
        ->and($activity->subject_type)->toBe($this->organization->getMorphClass())
        ->and((string) $activity->subject_id)->toBe((string) $this->organization->getKey())
        ->and((string) $activity->causer_id)->toBe((string) $this->owner->getKey())
        ->and($activity->properties->all())->toMatchArray([
            'member_count' => 2,
            'logout_token_count' => 2,
        ]);
});
