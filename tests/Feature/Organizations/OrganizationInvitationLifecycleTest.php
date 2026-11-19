<?php

declare(strict_types=1);

use App\Actions\ResendOrganizationInvitationAction;
use App\Enums\OrganizationRoleEnum;
use App\Http\Responses\RenderHttpErrorResponse;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Organisation invitations beyond the happy path in OrganizationInvitationTest (#180): the accept
 * page's four states, resend and revoke, and the organisation boundary.
 *
 * `{invitation}` binds on the invitation's own primary key and is **not** scoped to the
 * `{organization}` in the path, so the actions carry the boundary check. That is what the
 * cross-organisation cases below pin.
 */
function lifecycleInvitation(Organization $organization, User $invitedBy, string $plainToken, array $attributes = []): OrganizationInvitation
{
    return OrganizationInvitation::query()->create([
        'organization_id' => $organization->getKey(),
        'email' => 'invitee@example.test',
        'roles' => [OrganizationRoleEnum::Member->value],
        'token_hash' => hash('sha256', $plainToken),
        'invited_by_user_id' => $invitedBy->getKey(),
        'expires_at' => now()->addDay(),
        ...$attributes,
    ]);
}

/**
 * A token that matches nothing and a token that was already used answer identically, so the
 * accept page is not an oracle for which invitations exist. A guest gets the login redirect
 * every web 404 gives a guest ({@see RenderHttpErrorResponse}); a
 * signed-in caller gets the 404 itself.
 */
it('answers an unknown and an already-used accept token the same way', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'accepted-already']);
    lifecycleInvitation($organization, $owner, 'already-accepted-token', ['accepted_at' => now()]);

    $unknown = $this->get(route('invitations.accept', ['token' => 'no-such-invitation-token']));
    $used = $this->get(route('invitations.accept', ['token' => 'already-accepted-token']));

    $unknown->assertRedirect(route('login'));
    $used->assertRedirect(route('login'));

    expect($used->status())->toBe($unknown->status());

    $signedIn = User::factory()->create();

    $this->actingAs($signedIn)->get(route('invitations.accept', ['token' => 'no-such-invitation-token']))->assertNotFound();
    $this->actingAs($signedIn)->get(route('invitations.accept', ['token' => 'already-accepted-token']))->assertNotFound();
});

it('shows the expired state for an invitation past its expiry', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['name' => 'Expired Corp', 'slug' => 'expired-corp']);
    $invitation = lifecycleInvitation($organization, $owner, 'expired-token', ['expires_at' => now()->subMinute()]);

    $this->get(route('invitations.accept', ['token' => 'expired-token']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('status', 'expired')
            ->where('organizationName', 'Expired Corp')
            ->where('email', 'invitee@example.test')
            ->where('token', 'expired-token'));

    expect($invitation->isExpired())->toBeTrue()
        ->and($invitation->isPending())->toBeFalse();
});

it('shows the guest state to a visitor and remembers where to come back to', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['name' => 'Guest Corp', 'slug' => 'guest-corp']);
    $invitation = lifecycleInvitation($organization, $owner, 'guest-state-token');

    $this->get(route('invitations.accept', ['token' => 'guest-state-token']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('status', 'guest')
            ->where('organizationName', 'Guest Corp'));

    expect(session('url.intended'))->toBe(route('invitations.accept', ['token' => 'guest-state-token']))
        ->and($invitation->isPending())->toBeTrue();
});

it('shows the ready state to a signed-in user', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['name' => 'Ready Corp', 'slug' => 'ready-corp']);
    lifecycleInvitation($organization, $owner, 'ready-state-token');

    $this->actingAs(User::factory()->create(['email' => 'invitee@example.test']))
        ->get(route('invitations.accept', ['token' => 'ready-state-token']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/accept-invitation')
            ->where('status', 'ready')
            ->where('organizationName', 'Ready Corp'));
});

it('sends a guest who posts an accept to the login page and joins nobody', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'guest-post-corp']);
    lifecycleInvitation($organization, $owner, 'guest-post-token');

    $this->post(route('invitations.accept.store', ['token' => 'guest-post-token']))
        ->assertRedirect(route('login'));

    expect(OrganizationMembership::query()->where('organization_id', $organization->getKey())->count())->toBe(1);
});

it('refuses to accept an expired invitation', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'expired-accept-corp']);
    lifecycleInvitation($organization, $owner, 'expired-accept-token', ['expires_at' => now()->subSecond()]);
    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->from(route('invitations.accept', ['token' => 'expired-accept-token']))
        ->post(route('invitations.accept.store', ['token' => 'expired-accept-token']))
        ->assertSessionHasErrors('token');

    expect(OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $invitee->getKey())
        ->exists())->toBeFalse();
});

it('refuses to accept an invitation a second time', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'twice-corp']);
    lifecycleInvitation($organization, $owner, 'twice-token');
    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    $this->actingAs($invitee)
        ->post(route('invitations.accept.store', ['token' => 'twice-token']))
        ->assertRedirect(route('organizations.show', $organization));

    $this->actingAs($invitee)
        ->from(route('invitations.accept', ['token' => 'twice-token']))
        ->post(route('invitations.accept.store', ['token' => 'twice-token']))
        ->assertSessionHasErrors('token');

    expect(OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $invitee->getKey())
        ->count())->toBe(1);
});

it('refuses to accept an invitation whose organisation is gone', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'vanished-corp']);
    lifecycleInvitation($organization, $owner, 'vanished-token');
    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    OrganizationMembership::query()->where('organization_id', $organization->getKey())->delete();
    Organization::query()->whereKey($organization->getKey())->delete();

    $this->actingAs($invitee)
        ->from(route('invitations.accept', ['token' => 'vanished-token']))
        ->post(route('invitations.accept.store', ['token' => 'vanished-token']))
        ->assertSessionHasErrors('token');

    expect(OrganizationMembership::query()->where('user_id', $invitee->getKey())->exists())->toBeFalse();
});

it('refuses resend and revoke to a plain member of the organisation', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'member-refusal-corp']);
    $member = User::factory()->create();
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);
    $invitation = lifecycleInvitation($organization, $owner, 'member-refusal-token');
    $tokenHashBefore = $invitation->token_hash;

    Notification::fake();

    $this->actingAs($member)->put(route('organizations.invitations.update', [$organization, $invitation]))->assertForbidden();
    $this->actingAs($member)->delete(route('organizations.invitations.destroy', [$organization, $invitation]))->assertForbidden();
    $this->actingAs($member)->post(route('organizations.invitations.store', $organization), [
        'email' => 'smuggled@example.test',
        'roles' => [OrganizationRoleEnum::Owner->value],
    ])->assertForbidden();

    Notification::assertNothingSent();

    expect($invitation->fresh()?->token_hash)->toBe($tokenHashBefore)
        ->and(OrganizationInvitation::query()->where('email', 'smuggled@example.test')->exists())->toBeFalse();
});

it('refuses the invitations view and its writes to someone outside the organisation', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'outsider-corp']);
    $invitation = lifecycleInvitation($organization, $owner, 'outsider-token');
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('organizations.invitations.index', $organization))->assertForbidden();
    $this->actingAs($outsider)->put(route('organizations.invitations.update', [$organization, $invitation]))->assertForbidden();
    $this->actingAs($outsider)->delete(route('organizations.invitations.destroy', [$organization, $invitation]))->assertForbidden();

    expect($invitation->fresh())->not->toBeNull();
});

it('sends a guest to login rather than showing the invitations view', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'guest-view-corp']);

    $this->get(route('organizations.invitations.index', $organization))->assertRedirect(route('login'));
});

it('will not resend an invitation that belongs to another organisation', function (): void {
    $ownerOfBoth = User::factory()->create();
    $theirs = createOrganizationWithOwner($ownerOfBoth, ['slug' => 'theirs-corp']);
    $mine = createOrganizationWithOwner($ownerOfBoth, ['slug' => 'mine-corp']);
    $theirInvitation = lifecycleInvitation($theirs, $ownerOfBoth, 'cross-org-token');
    $tokenHashBefore = $theirInvitation->token_hash;
    $expiresAtBefore = $theirInvitation->expires_at;

    Notification::fake();

    // `theirs-corp`'s invitation, addressed under `mine-corp`.
    $this->actingAs($ownerOfBoth)
        ->from(route('organizations.invitations.index', $mine))
        ->put(route('organizations.invitations.update', [$mine, $theirInvitation]))
        ->assertSessionHasErrors('invitation');

    Notification::assertNothingSent();

    $untouched = $theirInvitation->fresh();

    expect($untouched?->token_hash)->toBe($tokenHashBefore)
        ->and($untouched?->expires_at->equalTo($expiresAtBefore))->toBeTrue();
});

it('will not revoke an invitation that belongs to another organisation', function (): void {
    $ownerOfBoth = User::factory()->create();
    $theirs = createOrganizationWithOwner($ownerOfBoth, ['slug' => 'revoke-theirs-corp']);
    $mine = createOrganizationWithOwner($ownerOfBoth, ['slug' => 'revoke-mine-corp']);
    $theirInvitation = lifecycleInvitation($theirs, $ownerOfBoth, 'cross-org-revoke-token');

    $this->actingAs($ownerOfBoth)
        ->from(route('organizations.invitations.index', $mine))
        ->delete(route('organizations.invitations.destroy', [$mine, $theirInvitation]))
        ->assertSessionHasErrors('invitation');

    expect(OrganizationInvitation::query()->whereKey($theirInvitation->getKey())->exists())->toBeTrue();
});

it('will not resend or revoke an invitation that was already accepted', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'accepted-corp']);
    $invitation = lifecycleInvitation($organization, $owner, 'accepted-token', ['accepted_at' => now()]);
    $tokenHashBefore = $invitation->token_hash;

    Notification::fake();

    $this->actingAs($owner)
        ->from(route('organizations.invitations.index', $organization))
        ->put(route('organizations.invitations.update', [$organization, $invitation]))
        ->assertSessionHasErrors('invitation');

    $this->actingAs($owner)
        ->from(route('organizations.invitations.index', $organization))
        ->delete(route('organizations.invitations.destroy', [$organization, $invitation]))
        ->assertSessionHasErrors('invitation');

    Notification::assertNothingSent();

    expect($invitation->fresh()?->token_hash)->toBe($tokenHashBefore)
        ->and(OrganizationInvitation::query()->whereKey($invitation->getKey())->exists())->toBeTrue();
});

it('rotates the token and extends the expiry on a resend, retiring the old link', function (): void {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['name' => 'Resend Corp', 'slug' => 'resend-corp']);
    $invitation = lifecycleInvitation($organization, $owner, 'first-issued-token', [
        'expires_at' => now()->addHours(2),
    ]);

    $this->actingAs($owner)
        ->put(route('organizations.invitations.update', [$organization, $invitation]))
        ->assertRedirect(route('organizations.invitations.index', $organization));

    $resent = $invitation->fresh();

    expect($resent?->token_hash)->not->toBe(hash('sha256', 'first-issued-token'))
        ->and($resent?->expires_at->greaterThan(now()->addDays(6)))->toBeTrue()
        ->and($resent?->accepted_at)->toBeNull();

    Notification::assertSentOnDemand(OrganizationInvitationNotification::class);

    // The link from the first email no longer resolves.
    $this->actingAs($owner)
        ->get(route('invitations.accept', ['token' => 'first-issued-token']))
        ->assertNotFound();
});

it('names the person who resent the invitation as its inviter', function (): void {
    Notification::fake();

    $originalInviter = User::factory()->create();
    $organization = createOrganizationWithOwner($originalInviter, ['slug' => 'inviter-corp']);
    $coOwner = User::factory()->create();
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $coOwner->getKey(),
        'roles' => [OrganizationRoleEnum::Admin->value],
    ]);
    $invitation = lifecycleInvitation($organization, $originalInviter, 'inviter-token');

    $this->actingAs($coOwner)
        ->put(route('organizations.invitations.update', [$organization, $invitation]))
        ->assertRedirect(route('organizations.invitations.index', $organization));

    $resent = $invitation->fresh();

    expect($resent?->invited_by_user_id)->toBe($coOwner->getKey())
        ->and($resent?->invitedBy?->is($coOwner))->toBeTrue()
        ->and($resent?->organization?->is($organization))->toBeTrue();
});

it('lets an instance admin delete an organisation they are not a member of', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'instance-admin-delete-corp']);
    $outsider = User::factory()->create();

    // Not a member, not an admin: refused.
    $this->actingAs($outsider)->delete(route('organizations.destroy', $organization))->assertForbidden();

    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeTrue();

    // An instance admin owns every organisation on the instance.
    $this->actingAs(instanceAdmin())->delete(route('organizations.destroy', $organization))->assertRedirect();

    expect(Organization::query()->whereKey($organization->getKey())->exists())->toBeFalse();
});

it('still lets the invitee accept with the link the resend emailed', function (): void {
    Notification::fake();

    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'resend-accept-corp']);
    $invitation = lifecycleInvitation($organization, $owner, 'pre-resend-token', [
        'roles' => [OrganizationRoleEnum::Admin->value],
    ]);
    $invitee = User::factory()->create(['email' => 'invitee@example.test']);

    app(ResendOrganizationInvitationAction::class)->execute($organization, $invitation, $owner);

    // Read the accept link out of the mail the invitee actually receives.
    $acceptUrl = null;
    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification, array $channels, object $notifiable) use (&$acceptUrl): bool {
            $acceptUrl = $notification->toMail($notifiable)->actionUrl;

            return true;
        },
    );

    expect($acceptUrl)->toBeString()->toContain('/invitations/')
        ->and($acceptUrl)->not->toContain('pre-resend-token');

    $this->actingAs($invitee)->post((string) $acceptUrl)
        ->assertRedirect(route('organizations.show', $organization));

    expect(OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $invitee->getKey())
        ->first()?->roles)->toBe([OrganizationRoleEnum::Admin->value]);
});

it('lists only the pending invitations of the organisation in the view', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'listing-corp']);
    lifecycleInvitation($organization, $owner, 'pending-listing-token');
    lifecycleInvitation($organization, $owner, 'accepted-listing-token', [
        'email' => 'gone@example.test',
        'accepted_at' => now(),
    ]);

    $elsewhereOwner = User::factory()->create();
    $elsewhere = createOrganizationWithOwner($elsewhereOwner, ['slug' => 'elsewhere-listing-corp']);
    lifecycleInvitation($elsewhere, $elsewhereOwner, 'other-listing-token', ['email' => 'other@example.test']);

    $response = $this->actingAs($owner)
        ->get(route('organizations.invitations.index', $organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organizations/invitations')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'invitee@example.test')
            ->where('invitations.0.is_expired', false)
            ->where('invitations.0.roles', [OrganizationRoleEnum::Member->value])
            ->where('can.manageMembers', true));

    expect($response->getContent())->not->toContain('other@example.test')
        ->not->toContain('gone@example.test');
});

it('marks an expired invitation as expired in the view, for a member who may not manage', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'expired-listing-corp']);
    lifecycleInvitation($organization, $owner, 'expired-listing-token', ['expires_at' => now()->subDay()]);
    $member = User::factory()->create();
    OrganizationMembership::query()->create([
        'organization_id' => $organization->getKey(),
        'user_id' => $member->getKey(),
        'roles' => [OrganizationRoleEnum::Member->value],
    ]);

    $this->actingAs($member)
        ->get(route('organizations.invitations.index', $organization))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('invitations.0.is_expired', true)
            ->where('can.manageMembers', false));
});
