<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Activity;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/**
 * Fleet core schema: activity rows that happen inside an organisation carry
 * `organisation_id`; everything else stays null (#192).
 */
test('activity_log has a nullable indexed organisation_id column', function (): void {
    expect(Schema::hasColumn('activity_log', 'organisation_id'))->toBeTrue();
});

test('organisation create stamps organisation_id on the activity row', function (): void {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('organizations.store'), [
            'name' => 'Acme',
            'slug' => 'acme-org',
        ])
        ->assertRedirect();

    $organizationId = Organization::query()->where('slug', 'acme-org')->value('id');

    $activity = Activity::query()
        ->where('event', 'organization_created')
        ->where('organisation_id', $organizationId)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->organisation_id)->toBe($organizationId);
});

test('organisation update stamps organisation_id', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'upd-org']);

    $this->actingAs($owner)
        ->put(route('organizations.update', $organization), [
            'name' => 'Updated Org',
            'slug' => 'upd-org',
            'is_mfa_required' => '0',
        ])
        ->assertRedirect();

    $activity = Activity::query()
        ->where('event', 'organization_updated')
        ->where('organisation_id', $organization->getKey())
        ->first();

    expect($activity)->not->toBeNull();
});

test('organisation delete stamps organisation_id', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'del-org']);
    $organizationId = $organization->getKey();

    $this->actingAs($owner)
        ->delete(route('organizations.destroy', $organization))
        ->assertRedirect();

    $activity = Activity::query()
        ->where('event', 'organization_deleted')
        ->where('organisation_id', $organizationId)
        ->first();

    expect($activity)->not->toBeNull();
});

test('membership add remove and role change stamp organisation_id', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'mem-org']);

    $this->actingAs($owner)
        ->post(route('organizations.members.store', $organization), [
            'user_id' => $member->getKey(),
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect();

    expect(Activity::query()
        ->where('event', 'organization_member_added')
        ->where('organisation_id', $organization->getKey())
        ->exists())->toBeTrue();

    $this->actingAs($owner)
        ->put(route('organizations.members.update', [$organization, $member]), [
            'roles' => [OrganizationRoleEnum::Admin->value],
        ])
        ->assertRedirect();

    expect(Activity::query()
        ->where('event', 'organization_member_roles_updated')
        ->where('organisation_id', $organization->getKey())
        ->exists())->toBeTrue();

    $this->actingAs($owner)
        ->delete(route('organizations.members.destroy', [$organization, $member]))
        ->assertRedirect();

    expect(Activity::query()
        ->where('event', 'organization_member_removed')
        ->where('organisation_id', $organization->getKey())
        ->exists())->toBeTrue();
});

test('invitations stamp organisation_id', function (): void {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'inv-org']);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'invitee@example.test',
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect();

    expect(Activity::query()
        ->where('event', 'organization_invitation_created')
        ->where('organisation_id', $organization->getKey())
        ->exists())->toBeTrue();

    $invitation = OrganizationInvitation::query()
        ->where('organization_id', $organization->getKey())
        ->where('email', 'invitee@example.test')
        ->firstOrFail();

    $this->actingAs($owner)
        ->put(route('organizations.invitations.update', [$organization, $invitation]))
        ->assertRedirect();

    expect(Activity::query()
        ->where('event', 'organization_invitation_resent')
        ->where('organisation_id', $organization->getKey())
        ->exists())->toBeTrue();

    $this->actingAs($owner)
        ->delete(route('organizations.invitations.destroy', [$organization, $invitation]))
        ->assertRedirect();

    expect(Activity::query()
        ->where('event', 'organization_invitation_revoked')
        ->where('organisation_id', $organization->getKey())
        ->exists())->toBeTrue();
});

test('mfa requirement toggle stamps organisation_id', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'mfa-org']);

    $this->actingAs($owner)
        ->put(route('organizations.update', $organization), [
            'name' => $organization->name,
            'slug' => $organization->slug,
            'is_mfa_required' => '1',
        ])
        ->assertRedirect();

    $activity = Activity::query()
        ->where('event', 'organization_mfa_requirement_updated')
        ->where('organisation_id', $organization->getKey())
        ->first();

    expect($activity)->not->toBeNull();
});

test('non-organisation activity keeps organisation_id null', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'first_name' => 'Kept',
            'last_name' => 'Null',
            'email' => $user->email,
            'locale' => 'en',
            'timezone' => 'UTC',
        ])
        ->assertSessionHasNoErrors();

    $activity = Activity::query()
        ->where('subject_type', $user->getMorphClass())
        ->where('subject_id', (string) $user->getKey())
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->organisation_id)->toBeNull();
});
