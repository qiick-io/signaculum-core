<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Client;
use Spatie\Activitylog\Models\Activity;

/**
 * Turning on an organisation's MFA requirement revokes its members' existing grants (#163):
 * the gate runs only at `/oauth/authorize`, so a refresh token issued before the requirement
 * would otherwise keep minting access tokens for its whole lifetime.
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
 * @return array{access_token: string, refresh_token: string}
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
