<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;

function invitationOrganizationOwnedBy(User $owner, array $attributes = []): Organization
{
    $organization = Organization::factory()->create($attributes);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $owner->getKey(),
        'roles' => [OrganizationRoleEnum::Owner->value],
    ]);

    return $organization;
}

test('an owner invites a member by email and stores a hashed token', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'invite-acme']);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'New.Member@Example.test',
            'roles' => [OrganizationRoleEnum::Member->value, OrganizationRoleEnum::Billing->value],
        ])
        ->assertRedirect(route('organizations.invitations.index', $organization));

    $invitation = OrganizationInvitation::query()
        ->where('organization_id', $organization->getKey())
        ->where('email', 'new.member@example.test')
        ->firstOrFail();

    expect($invitation->roles)->toBe([
        OrganizationRoleEnum::Member->value,
        OrganizationRoleEnum::Billing->value,
    ])
        ->and($invitation->token_hash)->toHaveLength(64)
        ->and($invitation->accepted_at)->toBeNull();

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);
});

test('the invitee accepts and joins with the invited roles', function () {
    $owner = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'join@example.test']);
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'accept-acme']);

    $plainToken = 'plain-invitation-token-accept-01';
    OrganizationInvitation::query()->create([
        'organization_id' => $organization->getKey(),
        'email' => 'join@example.test',
        'roles' => [OrganizationRoleEnum::Admin->value],
        'token_hash' => hash('sha256', $plainToken),
        'invited_by_user_id' => $owner->getKey(),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($invitee)
        ->post(route('invitations.accept.store', ['token' => $plainToken]))
        ->assertRedirect(route('organizations.show', $organization));

    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $invitee->getKey())
        ->firstOrFail();

    expect($membership->roles)->toBe([OrganizationRoleEnum::Admin->value]);

    expect(
        OrganizationInvitation::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->value('accepted_at'),
    )->not->toBeNull();
});

test('a mismatched email cannot accept the invitation', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create(['email' => 'other@example.test']);
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'mismatch-acme']);

    $plainToken = 'plain-invitation-token-mismatch';
    OrganizationInvitation::query()->create([
        'organization_id' => $organization->getKey(),
        'email' => 'invitee@example.test',
        'roles' => [OrganizationRoleEnum::Member->value],
        'token_hash' => hash('sha256', $plainToken),
        'invited_by_user_id' => $owner->getKey(),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($otherUser)
        ->from(route('invitations.accept', ['token' => $plainToken]))
        ->post(route('invitations.accept.store', ['token' => $plainToken]))
        ->assertSessionHasErrors('token');

    expect(
        OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $otherUser->getKey())
            ->exists(),
    )->toBeFalse();
});

test('an owner can revoke a pending invitation', function () {
    $owner = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'revoke-acme']);

    $invitation = OrganizationInvitation::query()->create([
        'organization_id' => $organization->getKey(),
        'email' => 'revoke@example.test',
        'roles' => [OrganizationRoleEnum::Member->value],
        'token_hash' => hash('sha256', 'revoke-token'),
        'invited_by_user_id' => $owner->getKey(),
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($owner)
        ->delete(route('organizations.invitations.destroy', [$organization, $invitation]))
        ->assertRedirect(route('organizations.invitations.index', $organization));

    expect(OrganizationInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('organisation invitations and settings views render for a member', function () {
    $owner = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'views-acme']);

    $this->actingAs($owner)
        ->get(route('organizations.invitations.index', $organization))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organizations/invitations')
            ->where('view', 'invitations'));

    $this->actingAs($owner)
        ->get(route('organizations.settings', $organization))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organizations/settings')
            ->where('view', 'settings'));
});
