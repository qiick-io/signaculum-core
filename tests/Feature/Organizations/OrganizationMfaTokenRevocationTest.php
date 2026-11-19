<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Jobs\EndOrganizationMemberOidcSessionsJob;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

/**
 * MFA-related token behaviour for #163: turning the requirement on, joining an already-required
 * organisation, password changes, and short token lifetimes.
 */
beforeEach(function () {
    config(['oidc.issuer' => 'https://id.example.test']);

    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->organization = createOrganizationWithOwner($this->owner, ['slug' => 'tightening-org']);
    addOrganizationMember($this->organization, $this->member);

    $this->client = createOidcClient();
});

function addOrganizationMember(Organization $organization, User $member): void
{
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);
}

/**
 * Run the authorization code flow as `$user` and redeem the code.
 *
 * @return array{access_token: string, refresh_token: string, expires_in: int}
 */
function issueGrantForOrganizationMember(User $user, Client $client): array
{
    test()->actingAs($user);
    $pkce = pkcePair();
    $code = approveAuthorization($client, $pkce['challenge'], ['scope' => 'openid profile email qiick.orgs'])['code'];
    $response = redeemCode($client, $code, $pkce['verifier'])->assertOk();

    return [
        'access_token' => (string) $response->json('access_token'),
        'refresh_token' => (string) $response->json('refresh_token'),
        'expires_in' => (int) $response->json('expires_in'),
    ];
}

function refreshGrant(Client $client, string $refreshToken): TestResponse
{
    return test()->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'refresh_token' => $refreshToken,
    ]);
}

function setOrganizationMfaRequirement(User $owner, Organization $organization, bool $isMfaRequired): void
{
    test()->actingAs($owner)
        ->put(route('organizations.update', $organization), [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'is_mfa_required' => $isMfaRequired ? '1' : '0',
        ])
        ->assertRedirect(route('organizations.settings', $organization));
}

/**
 * The OIDC helpers leave a session identity behind; later bearer-token requests need it gone.
 */
function forgetAuthenticatedSession(): void
{
    app('auth')->forgetGuards();
    test()->flushSession();
}

test('turning on MFA revokes the members refresh and access tokens', function () {
    $tokens = issueGrantForOrganizationMember($this->member, $this->client);

    setOrganizationMfaRequirement($this->owner, $this->organization, true);

    refreshGrant($this->client, $tokens['refresh_token'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    $this->withToken($tokens['access_token'])
        ->get('/oauth/userinfo')
        ->assertUnauthorized();
});

test('turning on MFA revokes the grants of the owner who turned it on too', function () {
    $tokens = issueGrantForOrganizationMember($this->owner, $this->client);

    setOrganizationMfaRequirement($this->owner, $this->organization, true);

    refreshGrant($this->client, $tokens['refresh_token'])->assertStatus(400);
});

test('turning on MFA revokes an unredeemed authorization code of a member', function () {
    $this->actingAs($this->member);
    $pkce = pkcePair();
    $code = approveAuthorization($this->client, $pkce['challenge'])['code'];

    setOrganizationMfaRequirement($this->owner, $this->organization, true);

    redeemCode($this->client, $code, $pkce['verifier'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});

test('turning on MFA leaves the tokens of other organisations and of outsiders alone', function () {
    $otherOwner = User::factory()->create();
    $otherMember = User::factory()->create();
    $otherOrganization = createOrganizationWithOwner($otherOwner, ['slug' => 'other-org']);
    addOrganizationMember($otherOrganization, $otherMember);
    $outsider = User::factory()->create();

    $otherMemberTokens = issueGrantForOrganizationMember($otherMember, $this->client);
    $outsiderTokens = issueGrantForOrganizationMember($outsider, $this->client);

    setOrganizationMfaRequirement($this->owner, $this->organization, true);

    // userinfo first: a successful refresh rotates the pair and revokes the old access token.
    $this->withToken($otherMemberTokens['access_token'])
        ->get('/oauth/userinfo')
        ->assertOk();

    refreshGrant($this->client, $otherMemberTokens['refresh_token'])->assertOk();
    refreshGrant($this->client, $outsiderTokens['refresh_token'])->assertOk();
});

test('turning on MFA logs the revocation with counts and no token values', function () {
    $tokens = issueGrantForOrganizationMember($this->member, $this->client);

    setOrganizationMfaRequirement($this->owner, $this->organization, true);

    $activity = Activity::query()
        ->where('event', 'organization_member_tokens_revoked')
        ->sole();

    expect($activity->log_name)->toBe('organizations')
        ->and($activity->subject_type)->toBe($this->organization->getMorphClass())
        ->and((string) $activity->subject_id)->toBe((string) $this->organization->getKey())
        ->and((string) $activity->causer_id)->toBe((string) $this->owner->getKey())
        ->and($activity->properties->all())->toMatchArray([
            'revoked_access_token_count' => 1,
            'revoked_refresh_token_count' => 1,
            'revoked_authorization_code_count' => 0,
        ])
        ->and(json_encode($activity->properties))->not->toContain($tokens['refresh_token']);
});

test('the activity log page renders the revocation event', function () {
    setOrganizationMfaRequirement($this->owner, $this->organization, true);

    $this->actingAs(instanceAdmin())
        ->get(route('admin.activity-logs.index', ['event' => 'organization_member_tokens_revoked']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.event', 'organization_member_tokens_revoked')
            ->where('activityLogs.data.0.event', 'organization_member_tokens_revoked')
            ->where('activityLogs.data.0.subject.label', $this->organization->name));
});

test('saving without turning MFA on revokes nothing', function (bool $isMfaRequiredBefore, bool $isMfaRequiredAfter) {
    $tokens = issueGrantForOrganizationMember($this->owner, $this->client);
    $this->organization->forceFill(['is_mfa_required' => $isMfaRequiredBefore])->save();

    setOrganizationMfaRequirement($this->owner, $this->organization, $isMfaRequiredAfter);

    refreshGrant($this->client, $tokens['refresh_token'])->assertOk();

    expect(Activity::query()->where('event', 'organization_member_tokens_revoked')->exists())->toBeFalse();
})->with([
    'off stays off' => [false, false],
    'on is turned off' => [true, false],
]);

test('issue 163 five-step reproduction pins the step-up and the revoked grant', function () {
    $member = User::factory()->withTwoFactor()->create();
    addOrganizationMember($this->organization, $member);
    $tokens = issueGrantForOrganizationMember($member, $this->client);
    forgetAuthenticatedSession();

    // 1. Before the toggle the token carries the organisation and userinfo accepts it.
    $this->withToken($tokens['access_token'])
        ->get('/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('orgs.0.slug', $this->organization->slug);

    // 2. The owner turns on the requirement; existing grants are revoked.
    setOrganizationMfaRequirement($this->owner, $this->organization, true);

    // 3. A password-only session is sent through the step-up gate at /oauth/authorize.
    $this->actingAs($member)
        ->get(authorizeUrl($this->client, pkcePair()['challenge'], [
            'scope' => 'openid profile email qiick.orgs',
        ]))
        ->assertRedirect(route('login'));
    forgetAuthenticatedSession();

    // 4. The issue expected a successful refresh; the revocation makes it fail instead.
    refreshGrant($this->client, $tokens['refresh_token'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant')
        ->assertJsonPath('hint', 'Token has been revoked');

    // 5. The issue expected 200 from userinfo; the revoked access token is rejected too.
    $this->withToken($tokens['access_token'])
        ->get('/oauth/userinfo')
        ->assertUnauthorized();
});

test('joining an already-required organisation revokes earlier grants and queues back-channel logout', function () {
    Queue::fake();

    $member = User::factory()->withTwoFactor()->create();
    $requiredOrganization = createOrganizationWithOwner($this->owner, ['slug' => 'already-required-org']);
    setOrganizationMfaRequirement($this->owner, $requiredOrganization, true);

    $tokens = issueGrantForOrganizationMember($member, $this->client);
    forgetAuthenticatedSession();

    $this->withToken($tokens['access_token'])
        ->get('/oauth/userinfo')
        ->assertOk()
        ->assertJsonPath('orgs', []);

    $this->actingAs($this->owner)
        ->post(route('organizations.members.store', $requiredOrganization), [
            'user_id' => $member->getKey(),
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect(route('organizations.show', $requiredOrganization));
    forgetAuthenticatedSession();

    $this->withToken($tokens['access_token'])
        ->get('/oauth/userinfo')
        ->assertUnauthorized();

    refreshGrant($this->client, $tokens['refresh_token'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');

    Queue::assertPushed(
        EndOrganizationMemberOidcSessionsJob::class,
        fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === (int) $member->getKey(),
    );
});

test('joining an organisation without MFA leaves earlier grants intact', function () {
    Queue::fake();

    $member = User::factory()->create();
    $plainOrganization = createOrganizationWithOwner($this->owner, ['slug' => 'plain-join-org']);

    $tokens = issueGrantForOrganizationMember($member, $this->client);
    forgetAuthenticatedSession();

    $this->actingAs($this->owner)
        ->post(route('organizations.members.store', $plainOrganization), [
            'user_id' => $member->getKey(),
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect(route('organizations.show', $plainOrganization));
    forgetAuthenticatedSession();

    $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk();
    refreshGrant($this->client, $tokens['refresh_token'])->assertOk();

    Queue::assertNotPushed(EndOrganizationMemberOidcSessionsJob::class);
});

test('admin API membership create on an MFA organisation revokes the new members grants', function () {
    Queue::fake();

    $member = User::factory()->withTwoFactor()->create();
    $requiredOrganization = createOrganizationWithOwner($this->owner, ['slug' => 'api-mfa-join']);
    setOrganizationMfaRequirement($this->owner, $requiredOrganization, true);

    $tokens = issueGrantForOrganizationMember($member, $this->client);
    forgetAuthenticatedSession();

    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/organizations/api-mfa-join/memberships', [
            'user_id' => $member->subject,
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertCreated();

    refreshGrant($this->client, $tokens['refresh_token'])->assertStatus(400);
    Queue::assertPushed(
        EndOrganizationMemberOidcSessionsJob::class,
        fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === (int) $member->getKey(),
    );
});

test('accepting an invitation to an MFA organisation revokes earlier grants', function () {
    Queue::fake();
    Notification::fake();

    $invitee = User::factory()->withTwoFactor()->create(['email' => 'mfa-invitee@example.test']);
    $requiredOrganization = createOrganizationWithOwner($this->owner, ['slug' => 'invite-mfa-org']);
    setOrganizationMfaRequirement($this->owner, $requiredOrganization, true);

    $tokens = issueGrantForOrganizationMember($invitee, $this->client);
    forgetAuthenticatedSession();

    $plainToken = 'mfa-invite-accept-token';
    OrganizationInvitation::query()->create([
        'organization_id' => $requiredOrganization->getKey(),
        'email' => 'mfa-invitee@example.test',
        'roles' => [OrganizationRoleEnum::Member->value],
        'token_hash' => hash('sha256', $plainToken),
        'invited_by_user_id' => $this->owner->getKey(),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($invitee)
        ->post(route('invitations.accept.store', ['token' => $plainToken]))
        ->assertRedirect();

    refreshGrant($this->client, $tokens['refresh_token'])->assertStatus(400);
    Queue::assertPushed(
        EndOrganizationMemberOidcSessionsJob::class,
        fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === (int) $invitee->getKey(),
    );
});

test('joining an MFA organisation also revokes an unredeemed authorization code', function () {
    $member = User::factory()->withTwoFactor()->create();
    $requiredOrganization = createOrganizationWithOwner($this->owner, ['slug' => 'code-mfa-join']);
    setOrganizationMfaRequirement($this->owner, $requiredOrganization, true);

    $this->actingAs($member);
    $pkce = pkcePair();
    $code = approveAuthorization($this->client, $pkce['challenge'])['code'];
    forgetAuthenticatedSession();

    $this->actingAs($this->owner)
        ->post(route('organizations.members.store', $requiredOrganization), [
            'user_id' => $member->getKey(),
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect(route('organizations.show', $requiredOrganization));

    redeemCode($this->client, $code, $pkce['verifier'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});

test('changing a password from security settings revokes outstanding grants', function () {
    $tokens = issueGrantForOrganizationMember($this->member, $this->client);
    forgetAuthenticatedSession();

    $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk();

    $this->actingAs($this->member)
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'a-new-password-1A!',
            'password_confirmation' => 'a-new-password-1A!',
        ])
        ->assertRedirect();
    forgetAuthenticatedSession();

    $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertUnauthorized();
    refreshGrant($this->client, $tokens['refresh_token'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});

test('password reset revokes grants and ends every browser session', function () {
    Notification::fake();

    $tokens = issueGrantForOrganizationMember($this->member, $this->client);
    forgetAuthenticatedSession();

    $this->post(route('password.email'), ['email' => $this->member->email]);

    Notification::assertSentTo($this->member, ResetPassword::class, function (ResetPassword $notification) use ($tokens): bool {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $this->member->email,
            'password' => 'reset-password-1A!',
            'password_confirmation' => 'reset-password-1A!',
        ])->assertRedirect(route('login'));

        forgetAuthenticatedSession();

        $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertUnauthorized();
        refreshGrant($this->client, $tokens['refresh_token'])->assertStatus(400);

        return true;
    });
});

test('an admin setting a password revokes that users grants', function () {
    $admin = instanceAdmin();
    $tokens = issueGrantForOrganizationMember($this->member, $this->client);
    forgetAuthenticatedSession();

    $this->actingAs($admin)
        ->put(route('admin.users.update', $this->member), [
            'first_name' => $this->member->first_name,
            'last_name' => $this->member->last_name,
            'email' => $this->member->email,
            'password' => 'admin-set-password-1A!',
        ])
        ->assertRedirect(route('admin.users.index'));
    forgetAuthenticatedSession();

    refreshGrant($this->client, $tokens['refresh_token'])->assertStatus(400);
});

test('ldap password paths remain a no-op for token revocation', function () {
    $ldapUser = User::factory()->create([
        'ldap_guid' => (string) \Illuminate\Support\Str::uuid(),
        'ldap_dn' => 'cn=Directory Person,dc=local,dc=com',
    ]);

    expect($ldapUser->isLdapSourced())->toBeTrue();

    $tokens = issueGrantForOrganizationMember($ldapUser, $this->client);
    forgetAuthenticatedSession();

    app(\App\Actions\RevokeOnPasswordChangeAction::class)->execute($ldapUser);

    $this->withToken($tokens['access_token'])->get('/oauth/userinfo')->assertOk();
    refreshGrant($this->client, $tokens['refresh_token'])->assertOk();
});

test('issue 163: Passport keeps its one-year access and refresh token lifetimes', function () {
    $tokens = issueGrantForOrganizationMember($this->member, $this->client);

    // Passport rounds the header to whole seconds, so allow a one-second boundary slip.
    expect($tokens['expires_in'])->toBeGreaterThanOrEqual(31535999)
        ->toBeLessThanOrEqual(31536000)
        ->and(Passport::tokensExpireIn()->format('%y-%m-%d-%h-%i-%s'))->toBe('1-0-0-0-0-0')
        ->and(Passport::refreshTokensExpireIn()->format('%y-%m-%d-%h-%i-%s'))->toBe('1-0-0-0-0-0');
});
