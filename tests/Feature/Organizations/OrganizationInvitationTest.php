<?php

declare(strict_types=1);

use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

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

test('an owner invites by email and stores only the token hash', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'invite-acme']);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'new.member@example.com',
            'roles' => [
                OrganizationRoleEnum::Member->value,
                OrganizationRoleEnum::Billing->value,
            ],
        ])
        ->assertRedirect(route('organizations.invitations.index', $organization));

    $invitation = OrganizationInvitation::query()
        ->where('organization_id', $organization->getKey())
        ->where('email', 'new.member@example.com')
        ->firstOrFail();

    expect($invitation->roles)->toBe([
        OrganizationRoleEnum::Member->value,
        OrganizationRoleEnum::Billing->value,
    ])
        ->and($invitation->token_hash)->not->toBeEmpty()
        ->and($invitation->accepted_at)->toBeNull()
        ->and($invitation->expires_at->isFuture())->toBeTrue()
        ->and($invitation->invited_by_user_id)->toBe($owner->getKey());

    expect($invitation->getAttributes())->not->toHaveKey('token');

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification, array $channels, object $notifiable): bool {
            return $notifiable->routes['mail'] === 'new.member@example.com'
                && in_array('mail', $channels, true)
                && $notification->acceptUrl !== '';
        },
    );
});

test('a plain member cannot invite', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'no-invite']);

    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($member)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'outsider@example.com',
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertForbidden();

    Notification::assertNothingSent();
});

test('inviting an existing member email fails validation', function () {
    Notification::fake();

    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'already-member']);

    $this->actingAs($owner)
        ->from(route('organizations.invitations.index', $organization))
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'owner@example.com',
            'roles' => [OrganizationRoleEnum::Member->value],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('email');
});

test('an owner can resend and revoke a pending invitation', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'resend-revoke']);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'pending@example.com',
            'roles' => [OrganizationRoleEnum::Admin->value],
        ])
        ->assertRedirect();

    $invitation = OrganizationInvitation::query()
        ->where('email', 'pending@example.com')
        ->firstOrFail();

    $originalHash = $invitation->token_hash;

    $this->actingAs($owner)
        ->post(route('organizations.invitations.resend', [$organization, $invitation]))
        ->assertRedirect(route('organizations.invitations.index', $organization));

    $invitation->refresh();

    expect($invitation->token_hash)->not->toBe($originalHash);

    Notification::assertSentOnDemandTimes(OrganizationInvitationNotification::class, 2);

    $this->actingAs($owner)
        ->delete(route('organizations.invitations.destroy', [$organization, $invitation]))
        ->assertRedirect(route('organizations.invitations.index', $organization));

    expect(OrganizationInvitation::query()->whereKey($invitation->getKey())->exists())->toBeFalse();
});

test('a matching signed-in user accepts a valid invitation', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'accept-ok', 'name' => 'Accept Org']);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'invitee@example.com',
            'roles' => [OrganizationRoleEnum::Member->value],
        ]);

    /** @var string $plainToken */
    $plainToken = null;

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification) use (&$plainToken): bool {
            $plainToken = $notification->plainToken;

            return true;
        },
    );

    expect($plainToken)->not->toBeNull();

    $this->actingAs($invitee)
        ->get(route('invitations.accept', ['token' => $plainToken]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('organization.name', 'Accept Org')
            ->where('email', 'invitee@example.com')
            ->where('canAccept', true));

    $this->actingAs($invitee)
        ->post(route('invitations.accept', ['token' => $plainToken]))
        ->assertRedirect(route('organizations.show', $organization));

    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $invitee->getKey())
        ->firstOrFail();

    expect($membership->roles)->toBe([OrganizationRoleEnum::Member->value]);

    $invitation = OrganizationInvitation::query()
        ->where('organization_id', $organization->getKey())
        ->where('email', 'invitee@example.com')
        ->firstOrFail();

    expect($invitation->accepted_at)->not->toBeNull()
        ->and($invitation->token_hash)->toBeNull();
});

test('a guest sees the accept page but cannot accept until signed in', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'guest-accept']);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'guest@example.com',
            'roles' => [OrganizationRoleEnum::Member->value],
        ]);

    /** @var string $plainToken */
    $plainToken = null;

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification) use (&$plainToken): bool {
            $plainToken = $notification->plainToken;

            return true;
        },
    );

    $this->post(route('logout'));

    $this->get(route('invitations.accept', ['token' => $plainToken]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('canAccept', false));

    $this->post(route('invitations.accept', ['token' => $plainToken]))
        ->assertRedirect();

    expect(OrganizationInvitation::query()
        ->where('email', 'guest@example.com')
        ->whereNull('accepted_at')
        ->exists())->toBeTrue();
});

test('expired and mismatched invitations are refused', function () {
    Notification::fake();

    $owner = User::factory()->create();
    $wrongUser = User::factory()->create(['email' => 'wrong@example.com']);
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'refuse']);

    $this->actingAs($owner)
        ->post(route('organizations.invitations.store', $organization), [
            'email' => 'right@example.com',
            'roles' => [OrganizationRoleEnum::Member->value],
        ]);

    /** @var string $plainToken */
    $plainToken = null;

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification) use (&$plainToken): bool {
            $plainToken = $notification->plainToken;

            return true;
        },
    );

    $this->actingAs($wrongUser)
        ->post(route('invitations.accept', ['token' => $plainToken]))
        ->assertForbidden();

    OrganizationInvitation::query()
        ->where('email', 'right@example.com')
        ->update(['expires_at' => now()->subMinute()]);

    $rightUser = User::factory()->create(['email' => 'right@example.com']);

    $this->actingAs($rightUser)
        ->get(route('invitations.accept', ['token' => $plainToken]))
        ->assertNotFound();
});

test('organisation pages expose members invitations and settings views', function () {
    $owner = User::factory()->create();
    $organization = invitationOrganizationOwnedBy($owner, ['slug' => 'views', 'name' => 'Views Org']);

    $this->actingAs($owner)
        ->get(route('organizations.show', $organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/show')
            ->where('view', 'members')
            ->has('members'));

    $this->actingAs($owner)
        ->get(route('organizations.invitations.index', $organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/invitations')
            ->where('view', 'invitations')
            ->has('invitations'));

    $this->actingAs($owner)
        ->get(route('organizations.settings', $organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/settings')
            ->where('view', 'settings')
            ->where('organization.name', 'Views Org'));
});
