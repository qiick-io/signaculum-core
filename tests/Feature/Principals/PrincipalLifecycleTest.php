<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Actions\CreateOrganizationAction;
use App\Actions\DeactivateUserAction;
use App\Actions\ReactivateUserAction;
use App\Actions\RemoveOrganizationMemberAction;
use App\Data\OrganizationData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Enums\PrincipalTypeEnum;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Access\Principals\DeactivationReason;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\ApiKeys\RevocationReason;

/**
 * Fleet principals: one human principal per organisation membership; Admin API
 * keys belong to the singleton instance_service principal (#229).
 */
test('principals table exists with the fleet shape', function (): void {
    expect(Schema::hasTable('principals'))->toBeTrue()
        ->and(Schema::hasColumns('principals', [
            'id',
            'type',
            'user_id',
            'organisation_id',
            'name',
            'deactivated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('api_keys', 'owner_principal_id'))->toBeTrue();
});

test('creating a user does not create a principal', function (): void {
    $user = User::factory()->create(['first_name' => 'No', 'last_name' => 'Principal']);

    expect(Principal::query()->where('user_id', $user->getKey())->exists())->toBeFalse();
});

test('joining an organisation creates a user principal for that organisation', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create(['first_name' => 'Org', 'last_name' => 'Member']);

    $organization = app(CreateOrganizationAction::class)->execute(
        new OrganizationData('Acme', 'acme-'.Str::lower(Str::random(6))),
        $owner,
    );

    app(AddOrganizationMemberAction::class)->execute(
        $organization,
        $member,
        [OrganizationRoleEnum::Member->value],
    );

    $ownerPrincipal = Principal::query()
        ->where('user_id', $owner->getKey())
        ->where('organisation_id', $organization->getKey())
        ->first();

    $memberPrincipal = Principal::query()
        ->where('user_id', $member->getKey())
        ->where('organisation_id', $organization->getKey())
        ->first();

    expect($ownerPrincipal)->not->toBeNull()
        ->and($ownerPrincipal?->type)->toBe(PrincipalTypeEnum::User->value)
        ->and($ownerPrincipal?->organisation_id)->toBe($organization->getKey())
        ->and($memberPrincipal)->not->toBeNull()
        ->and($memberPrincipal?->deactivated_at)->toBeNull();
});

test('leaving an organisation deactivates that membership principal', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = app(CreateOrganizationAction::class)->execute(
        new OrganizationData('Leave Co', 'leave-'.Str::lower(Str::random(6))),
        $owner,
    );

    app(AddOrganizationMemberAction::class)->execute(
        $organization,
        $member,
        [OrganizationRoleEnum::Member->value],
    );

    $principal = Principal::query()
        ->where('user_id', $member->getKey())
        ->where('organisation_id', $organization->getKey())
        ->firstOrFail();

    app(RemoveOrganizationMemberAction::class)->execute($organization, $member);

    expect($principal->refresh()->deactivated_at)->not->toBeNull();
});

test('rejoining an organisation reactivates the same membership principal', function (): void {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $organization = app(CreateOrganizationAction::class)->execute(
        new OrganizationData('Rejoin Co', 'rejoin-'.Str::lower(Str::random(6))),
        $owner,
    );

    app(AddOrganizationMemberAction::class)->execute(
        $organization,
        $member,
        [OrganizationRoleEnum::Member->value],
    );

    $principal = Principal::query()
        ->where('user_id', $member->getKey())
        ->where('organisation_id', $organization->getKey())
        ->firstOrFail();

    app(RemoveOrganizationMemberAction::class)->execute($organization, $member);

    expect($principal->refresh()->deactivated_at)->not->toBeNull();

    app(AddOrganizationMemberAction::class)->execute(
        $organization,
        $member,
        [OrganizationRoleEnum::Member->value],
    );

    expect(Principal::query()->where('user_id', $member->getKey())->where('organisation_id', $organization->getKey())->count())->toBe(1)
        ->and($principal->refresh()->deactivated_at)->toBeNull();
});

test('creating an api key writes owner_principal_id to the instance_service principal', function (): void {
    ['admin' => $admin, 'apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);

    $instancePrincipal = Principal::query()
        ->where('type', PrincipalTypeEnum::InstanceService)
        ->firstOrFail();

    expect($apiKey->owner_principal_id)->toBe($instancePrincipal->id)
        ->and($apiKey->created_by)->toBe($admin->getKey())
        ->and(ApiKey::query()->whereKey($apiKey->getKey())->value('owner_principal_id'))
        ->toBe($instancePrincipal->id)
        ->and($instancePrincipal->organisation_id)->toBeNull()
        ->and($instancePrincipal->user_id)->toBeNull();
});

test('Admin API keys only hold instance scopes and never an organisation-scoped scope', function (): void {
    ['apiKey' => $apiKey] = adminApiKey([
        ApiKeyScopeEnum::UsersWrite->value,
        ApiKeyScopeEnum::OrganizationsWrite->value,
        ApiKeyScopeEnum::ApplicationsWrite->value,
    ]);

    $allowedScopes = ApiKeyScopeEnum::values();
    $storedScopes = $apiKey->scopes ?? [];

    expect($storedScopes)->not->toBeEmpty();

    foreach ($storedScopes as $scope) {
        expect($allowedScopes)->toContain($scope)
            ->and($scope)->not->toContain('organisation:')
            ->and($scope)->not->toContain('organization:');
    }
});

test('deactivating a user also sets membership principals.deactivated_at', function (): void {
    User::factory()->instanceAdmin()->create();
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    app(AddOrganizationMemberAction::class)->execute(
        $organization,
        $user,
        [OrganizationRoleEnum::Member->value],
    );

    $principal = Principal::query()
        ->where('user_id', $user->getKey())
        ->where('organisation_id', $organization->getKey())
        ->firstOrFail();

    expect($principal->deactivated_at)->toBeNull();

    app(DeactivateUserAction::class)->execute($user);

    expect($user->refresh()->is_active)->toBeFalse()
        ->and($principal->refresh()->deactivated_at)->not->toBeNull();
});

test('reactivating a user clears principals.deactivated_at only for current memberships', function (): void {
    User::factory()->instanceAdmin()->create();
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    app(AddOrganizationMemberAction::class)->execute(
        $organization,
        $user,
        [OrganizationRoleEnum::Member->value],
    );

    $principal = Principal::query()
        ->where('user_id', $user->getKey())
        ->where('organisation_id', $organization->getKey())
        ->firstOrFail();

    app(DeactivateUserAction::class)->execute($user);

    expect($principal->refresh()->deactivated_at)->not->toBeNull();

    app(ReactivateUserAction::class)->execute($user->refresh());

    expect($user->refresh()->is_active)->toBeTrue()
        ->and($principal->refresh()->deactivated_at)->toBeNull();
});

// Reactivating a user undoes only what deactivating the user did (`admin`). A principal
// deactivated for another reason (here with its owner, or expired) stays inactive, even in an
// organisation the user still belongs to: that decision was not the user deactivation's.
test('reactivating a user does not revive a principal deactivated for another reason', function (string $otherReason): void {
    User::factory()->instanceAdmin()->create();
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    app(AddOrganizationMemberAction::class)->execute($organization, $user, [OrganizationRoleEnum::Member->value]);
    $principal = Principal::query()->where('user_id', $user->getKey())->where('organisation_id', $organization->getKey())->firstOrFail();
    Principal::query()->whereKey($principal->getKey())->update(['deactivated_at' => now(), 'deactivation_reason' => $otherReason]);

    app(DeactivateUserAction::class)->execute($user);
    app(ReactivateUserAction::class)->execute($user->refresh());

    expect($principal->refresh()->deactivated_at)->not->toBeNull()
        ->and($principal->deactivation_reason?->value)->toBe($otherReason);
})->with(['owner deactivated' => 'owner_deactivated', 'expired' => 'expired']);

// qiick/laravel-access: a deactivated principal's API keys stop, through the PrincipalDeactivated
// listener, including every Admin API key when the instance_service principal is deactivated.
test('deactivating the instance service principal revokes the Admin API keys it owns', function (): void {
    ['apiKey' => $apiKey] = adminApiKey([ApiKeyScopeEnum::UsersRead->value]);
    $instancePrincipal = Principal::query()->whereKey($apiKey->owner_principal_id)->firstOrFail();

    app(PrincipalDirectory::class)->deactivate($instancePrincipal, DeactivationReason::Admin);

    expect($apiKey->refresh()->revoked_at)->not->toBeNull()
        ->and($apiKey->revokedReason())->toBe(RevocationReason::Owner);
});

// A membership principal is active exactly while the account is active and the membership
// exists, whatever order deactivation, removal, reactivation and rejoining happen in.
test('a deactivated user removed from an organisation gets an active principal back on reactivation and rejoining', function (string $order): void {
    User::factory()->instanceAdmin()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->create();
    app(AddOrganizationMemberAction::class)->execute($organization, $member, [OrganizationRoleEnum::Member->value]);
    $principal = Principal::query()->where('user_id', $member->getKey())->where('organisation_id', $organization->getKey())->firstOrFail();

    app(DeactivateUserAction::class)->execute($member);
    app(RemoveOrganizationMemberAction::class)->execute($organization, $member);

    $steps = [
        'reactivate' => fn () => app(ReactivateUserAction::class)->execute($member->refresh()),
        'rejoin' => fn () => app(AddOrganizationMemberAction::class)->execute($organization, $member->refresh(), [OrganizationRoleEnum::Member->value]),
    ];
    [$first, $second] = $order === 'reactivate first' ? ['reactivate', 'rejoin'] : ['rejoin', 'reactivate'];

    $steps[$first]();

    // Only one of the two conditions holds yet: the principal stays inactive.
    expect($principal->refresh()->deactivated_at)->not->toBeNull();

    $steps[$second]();

    expect($principal->refresh()->deactivated_at)->toBeNull()
        ->and($principal->deactivation_reason)->toBeNull();
})->with(['reactivate first', 'rejoin first']);

test('reactivating a user never revives the principal of a membership removed meanwhile', function (): void {
    User::factory()->instanceAdmin()->create();
    $member = User::factory()->create();
    $organization = Organization::factory()->create();
    app(AddOrganizationMemberAction::class)->execute($organization, $member, [OrganizationRoleEnum::Member->value]);
    $principal = Principal::query()->where('user_id', $member->getKey())->where('organisation_id', $organization->getKey())->firstOrFail();

    app(DeactivateUserAction::class)->execute($member);
    app(RemoveOrganizationMemberAction::class)->execute($organization, $member);
    app(ReactivateUserAction::class)->execute($member->refresh());

    expect($principal->refresh()->deactivated_at)->not->toBeNull()
        ->and($principal->deactivation_reason)->toBe(DeactivationReason::Offboarded);
});

// The account state is read under a lock on the user row, never from the caller's copy: a
// deactivation that committed after the member was loaded still leaves the new principal inactive.
test('a membership created from a stale copy of a since deactivated account gets an inactive principal', function (): void {
    User::factory()->instanceAdmin()->create();
    $member = User::factory()->create();
    $staleMember = User::query()->findOrFail($member->getKey());
    $organization = Organization::factory()->create();

    app(DeactivateUserAction::class)->execute($member);

    expect($staleMember->is_active)->toBeTrue();

    app(AddOrganizationMemberAction::class)->execute($organization, $staleMember, [OrganizationRoleEnum::Member->value]);
    $principal = Principal::query()->where('user_id', $member->getKey())->where('organisation_id', $organization->getKey())->firstOrFail();

    expect($principal->deactivated_at)->not->toBeNull()
        ->and($principal->deactivation_reason)->toBe(DeactivationReason::Admin);
});

// Deactivation and reactivation write the state on the row they locked, not on the caller's copy:
// a copy that already holds the target value would make Eloquent skip the write.
test('reactivating from a stale copy that still reads active writes the account state', function (): void {
    User::factory()->instanceAdmin()->create();
    $member = User::factory()->create();
    $staleMember = User::query()->findOrFail($member->getKey());

    app(DeactivateUserAction::class)->execute($member);
    app(ReactivateUserAction::class)->execute($staleMember);

    expect(User::query()->whereKey($member->getKey())->value('is_active'))->toBeTruthy();
});

test('deactivating from a stale copy that already reads inactive writes the account state', function (): void {
    User::factory()->instanceAdmin()->create();
    $member = User::factory()->create();
    app(DeactivateUserAction::class)->execute($member);
    $staleMember = User::query()->findOrFail($member->getKey());

    app(ReactivateUserAction::class)->execute($member->refresh());
    app(DeactivateUserAction::class)->execute($staleMember);

    expect(User::query()->whereKey($member->getKey())->value('is_active'))->toBeFalsy();
});
