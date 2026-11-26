<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Actions\CreateOrganizationAction;
use App\Actions\DeleteOrganizationAction;
use App\Actions\ReactivateUserAction;
use App\Data\OrganizationData;
use App\Enums\OrganizationRoleEnum;
use App\Enums\PrincipalDeactivationReasonEnum;
use App\Enums\PrincipalTypeEnum;
use App\Enums\WebhookEventEnum;
use App\Jobs\EndOrganizationMemberOidcSessionsJob;
use App\Models\ApiKey;
use App\Models\ClientSession;
use App\Models\OidcSession;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Qiick\Outbound\Models\WebhookDelivery;
use Spatie\Activitylog\Models\Activity;

/*
 * Deleting an organisation is an offboarding job, never a database CASCADE (knowledge:
 * laravel-access design §15.5, decision of 2026-11-23). Principals and audit rows stay as
 * evidence, so `principals.organisation_id` is restrict and the organisation is soft-deleted;
 * its slug stays taken, so nobody can take over a deleted organisation's identity.
 */

/**
 * An organisation created the way the app creates one, with a second member.
 *
 * @return array{organization: Organization, owner: User, member: User}
 */
function offboardingOrganization(string $slug = 'offboarded-corp'): array
{
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = app(CreateOrganizationAction::class)->execute(new OrganizationData('Offboarded Corp', $slug), $owner);
    app(AddOrganizationMemberAction::class)->execute($organization, $member, [OrganizationRoleEnum::Member->value]);

    return ['organization' => $organization, 'owner' => $owner, 'member' => $member];
}

test('the database refuses to delete an organisation that still has principals', function () {
    ['organization' => $organization] = offboardingOrganization();

    expect(Principal::query()->where('organisation_id', $organization->getKey())->count())->toBe(2);

    expect(fn () => DB::table('organizations')->where('id', $organization->getKey())->delete())
        ->toThrow(QueryException::class);
});

test('deleting an organisation offboards it and keeps the evidence', function () {
    ['organization' => $organization, 'owner' => $owner] = offboardingOrganization();
    OrganizationInvitation::query()->create([
        'organization_id' => $organization->getKey(),
        'email' => 'pending@example.test',
        'roles' => [OrganizationRoleEnum::Member->value],
        'token_hash' => hash('sha256', 'pending-token'),
        'invited_by_user_id' => $owner->getKey(),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->delete(route('organizations.destroy', $organization))
        ->assertRedirect(route('organizations.index'));

    $principals = Principal::query()->where('organisation_id', $organization->getKey())->get();

    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeFalse()
        ->and(Organization::withTrashed()->whereKey($organization->getKey())->first()?->trashed())->toBeTrue()
        ->and(OrganizationMembership::query()->where('organization_id', $organization->getKey())->exists())->toBeFalse()
        ->and(OrganizationInvitation::query()->where('organization_id', $organization->getKey())->exists())->toBeFalse()
        ->and($principals)->toHaveCount(2)
        ->and($principals->every(fn (Principal $principal): bool => $principal->deactivated_at !== null))->toBeTrue()
        ->and($principals->every(fn (Principal $principal): bool => $principal->deactivation_reason === PrincipalDeactivationReasonEnum::Offboarded))->toBeTrue()
        ->and(Activity::query()->where('event', 'organization_deleted')->where('subject_id', $organization->getKey())->exists())->toBeTrue();
});

test('deleting an organisation revokes the api keys its principals own', function () {
    ['organization' => $organization, 'owner' => $owner] = offboardingOrganization();
    ['apiKey' => $apiKey] = adminApiKey();
    $ownerPrincipal = Principal::query()
        ->where('organisation_id', $organization->getKey())
        ->where('user_id', $owner->getKey())
        ->sole();
    $apiKey->forceFill(['owner_principal_id' => $ownerPrincipal->getKey()])->save();

    $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();

    expect(ApiKey::query()->whereKey($apiKey->getKey())->sole()->revoked_at)->not->toBeNull();
});

test('a deleted organisation keeps its slug taken', function () {
    ['organization' => $organization, 'owner' => $owner] = offboardingOrganization('taken-forever');

    $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();

    $this->actingAs(User::factory()->create())
        ->post(route('organizations.store'), ['name' => 'Impostor', 'slug' => 'taken-forever'])
        ->assertSessionHasErrors('slug');
});

test('removing a member deactivates their principal as offboarded', function () {
    ['organization' => $organization, 'owner' => $owner, 'member' => $member] = offboardingOrganization();

    $this->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $member]))
        ->assertRedirect();

    $principal = Principal::query()
        ->where('organisation_id', $organization->getKey())
        ->where('user_id', $member->getKey())
        ->where('type', PrincipalTypeEnum::User)
        ->sole();

    expect($principal->deactivated_at)->not->toBeNull()
        ->and($principal->deactivation_reason)->toBe(PrincipalDeactivationReasonEnum::Offboarded);
});

test('an api key always has an owner principal, which cannot be deleted under it', function () {
    ['apiKey' => $apiKey] = adminApiKey();

    expect(fn () => DB::table('api_keys')->where('id', $apiKey->getKey())->update(['owner_principal_id' => null]))
        ->toThrow(QueryException::class);

    expect(fn () => DB::table('principals')->where('id', $apiKey->owner_principal_id)->delete())
        ->toThrow(QueryException::class);
});

/**
 * A redeemed authorization code grant (access + refresh token) for `$user`.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function offboardingGrant(User $user, Client $client): array
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

// Members lose access at once, as the confirmation dialog says: their OAuth grants die and
// every relying party with a back-channel logout URI is told (as when the organisation
// turns MFA on, #163 / #174). Users outside the organisation keep theirs.
test('deleting an organisation revokes its members oauth grants and fans out back-channel logout', function () {
    ['organization' => $organization, 'owner' => $owner, 'member' => $member] = offboardingOrganization();
    $outsider = User::factory()->create();
    $client = createOidcClient();
    $memberGrant = offboardingGrant($member, $client);
    $outsiderGrant = offboardingGrant($outsider, $client);

    Queue::fake();

    $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();

    expect(Passport::token()->newQuery()->where('user_id', $member->getKey())->where('revoked', false)->exists())->toBeFalse()
        ->and(Passport::token()->newQuery()->where('user_id', $outsider->getKey())->where('revoked', false)->exists())->toBeTrue();

    $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->getKey(),
        'client_secret' => $client->plainSecret,
        'refresh_token' => $memberGrant['refresh_token'],
    ])->assertStatus(400);

    expect($outsiderGrant['refresh_token'])->not->toBe('');

    Queue::assertPushed(EndOrganizationMemberOidcSessionsJob::class, fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === $member->getKey());
    Queue::assertPushed(EndOrganizationMemberOidcSessionsJob::class, fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === $owner->getKey());
    Queue::assertNotPushed(EndOrganizationMemberOidcSessionsJob::class, fn (EndOrganizationMemberOidcSessionsJob $job): bool => $job->userId === $outsider->getKey());
});

// The same integration surface as removing one member: organization.membership_changed with
// action `removed`, once per member.
test('deleting an organisation sends a membership removed webhook for every member', function () {
    ['organization' => $organization, 'owner' => $owner, 'member' => $member] = offboardingOrganization();
    existingWebhook(['events' => [WebhookEventEnum::OrganizationMembershipChanged->value]]);

    $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();

    $bodies = WebhookDelivery::query()
        ->where('event_type', WebhookEventEnum::OrganizationMembershipChanged->value)
        ->get()
        ->map(fn (WebhookDelivery $delivery): array => json_decode((string) $delivery->payload, true, flags: JSON_THROW_ON_ERROR)['data']);

    expect($bodies)->toHaveCount(2);

    foreach ([$owner, $member] as $removedUser) {
        expect($bodies->contains(fn (array $data): bool => $data['organization_id'] === $organization->getKey()
            && $data['user_id'] === $removedUser->subject
            && $data['action'] === 'removed'))->toBeTrue();
    }
});

// The deliveries are stored in the offboarding transaction (an outbox): a failure while storing
// them rolls the whole offboarding back, so a retry still finds the organisation and sends all.
test('a failure while storing the membership webhooks rolls the offboarding back', function () {
    ['organization' => $organization, 'owner' => $owner] = offboardingOrganization();
    existingWebhook(['events' => [WebhookEventEnum::OrganizationMembershipChanged->value]]);
    $isStorageFailing = true;
    $storedDeliveries = 0;
    DB::beforeExecuting(function (string $query) use (&$isStorageFailing, &$storedDeliveries): void {
        if ($isStorageFailing && str_starts_with(strtolower(ltrim($query)), 'insert') && str_contains($query, 'webhook_deliveries') && ++$storedDeliveries === 2) {
            throw new RuntimeException('The delivery write failed.');
        }
    });

    expect(fn () => app(DeleteOrganizationAction::class)->execute($organization))->toThrow(RuntimeException::class, 'The delivery write failed.');

    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeTrue()
        ->and(OrganizationMembership::query()->where('organization_id', $organization->getKey())->count())->toBe(2)
        ->and(WebhookDelivery::query()->count())->toBe(0);

    $isStorageFailing = false;
    $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();

    expect(WebhookDelivery::query()->count())->toBe(2);
});

// A request that resolved the organisation before it was deleted must not add a member to it
// afterwards: membership writes lock the organisation row and refuse a deleted one.
test('a member cannot be added to an organisation deleted in the meantime', function () {
    ['organization' => $organization, 'owner' => $owner] = offboardingOrganization();
    $staleOrganization = Organization::query()->findOrFail($organization->getKey());
    $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();

    expect(fn () => app(AddOrganizationMemberAction::class)->execute($staleOrganization, User::factory()->create(), [OrganizationRoleEnum::Member->value]))
        ->toThrow(ValidationException::class);

    expect(OrganizationMembership::query()->where('organization_id', $organization->getKey())->exists())->toBeFalse();
});

// Defence in depth for rows left by a race before the lock existed: reactivating a user never
// revives their principal in a deleted organisation.
test('reactivating a user does not revive a principal of a deleted organisation', function () {
    ['organization' => $organization, 'owner' => $owner, 'member' => $member] = offboardingOrganization();
    $this->actingAs($owner)->delete(route('organizations.destroy', $organization))->assertRedirect();
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    app(ReactivateUserAction::class)->execute($member);

    $principal = Principal::query()->where('organisation_id', $organization->getKey())->where('user_id', $member->getKey())->sole();

    expect($principal->deactivated_at)->not->toBeNull()
        ->and($principal->deactivation_reason)->toBe(PrincipalDeactivationReasonEnum::Offboarded);
});

test('deleting an organisation twice offboards it once', function () {
    ['organization' => $organization, 'owner' => $owner] = offboardingOrganization();
    $staleOrganization = Organization::query()->findOrFail($organization->getKey());

    app(DeleteOrganizationAction::class)->execute($organization);
    app(DeleteOrganizationAction::class)->execute($staleOrganization);

    expect(Activity::query()->where('event', 'organization_deleted')->where('subject_id', $organization->getKey())->count())->toBe(1);
});

// Offboarding touches only the deleted organisation: a member of another organisation keeps
// that membership and its active principal (their grants are revoked user-wide, as for MFA).
test('a member of two organisations keeps the other one when one is deleted', function () {
    ['organization' => $deletedOrganization, 'owner' => $owner, 'member' => $member] = offboardingOrganization('deleted-corp');
    $otherOrganization = app(CreateOrganizationAction::class)->execute(new OrganizationData('Other Corp', 'other-corp'), User::factory()->create());
    app(AddOrganizationMemberAction::class)->execute($otherOrganization, $member, [OrganizationRoleEnum::Member->value]);

    $this->actingAs($owner)->delete(route('organizations.destroy', $deletedOrganization))->assertRedirect();

    $otherPrincipal = Principal::query()->where('organisation_id', $otherOrganization->getKey())->where('user_id', $member->getKey())->sole();

    expect(OrganizationMembership::query()->where('organization_id', $otherOrganization->getKey())->where('user_id', $member->getKey())->exists())->toBeTrue()
        ->and($otherPrincipal->deactivated_at)->toBeNull()
        ->and($otherPrincipal->deactivation_reason)->toBeNull();
});

// Back-channel logout is queued after commit: an offboarding that rolls back signs nobody out.
test('an offboarding that rolls back signs nobody out', function () {
    ['organization' => $organization, 'member' => $member] = offboardingOrganization();
    $client = createOidcClient();
    $client->forceFill(['backchannel_logout_uri' => 'https://rp.example.test/backchannel-logout'])->save();
    OidcSession::query()->create([
        'user_id' => $member->getKey(),
        'laravel_session_id' => 'laravel-rollback-sid',
        'oidc_session_id' => 'rollback-sid',
    ]);
    ClientSession::query()->create([
        'user_id' => $member->getKey(),
        'client_id' => $client->getKey(),
        'oidc_session_id' => 'rollback-sid',
    ]);
    Http::fake();
    Organization::deleting(fn () => throw new RuntimeException('Simulated failure at the end of the offboarding.'));

    // The sync queue runs a job at once, or at commit when it is dispatched after commit; the
    // logout token is an HTTP call to the relying party, which no rollback can undo.
    expect(fn () => app(DeleteOrganizationAction::class)->execute($organization))->toThrow(RuntimeException::class);

    Http::assertNothingSent();
    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeTrue();
});
