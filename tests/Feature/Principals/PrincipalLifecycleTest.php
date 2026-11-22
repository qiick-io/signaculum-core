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
        ->and($ownerPrincipal?->type)->toBe(PrincipalTypeEnum::User)
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
