<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Actions\CreateOrganizationAction;
use App\Data\OrganizationData;
use App\Enums\OrganizationRoleEnum;
use App\Enums\PrincipalDeactivationReasonEnum;
use App\Enums\PrincipalTypeEnum;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
