<?php

declare(strict_types=1);

use App\Enums\PrincipalTypeEnum;
use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Database CHECK + singleton: organisation_id is null iff type is instance_service (#229 nits).
 */
test('a user principal without organisation_id is rejected by the database', function (): void {
    $user = User::factory()->create();

    expect(fn () => Principal::query()->create([
        'type' => PrincipalTypeEnum::User,
        'user_id' => $user->getKey(),
        'organisation_id' => null,
        'name' => 'Orphan',
    ]))->toThrow(QueryException::class);
});

test('a service principal without organisation_id is rejected by the database', function (): void {
    expect(fn () => Principal::query()->create([
        'type' => PrincipalTypeEnum::Service,
        'user_id' => null,
        'organisation_id' => null,
        'name' => 'Bad service',
    ]))->toThrow(QueryException::class);
});

test('an instance_service principal may have a null organisation_id', function (): void {
    $principal = Principal::query()->create([
        'type' => PrincipalTypeEnum::InstanceService,
        'user_id' => null,
        'organisation_id' => null,
        'name' => 'Instance Admin API',
    ]);

    expect($principal->organisation_id)->toBeNull()
        ->and($principal->type)->toBe(PrincipalTypeEnum::InstanceService);
});

test('an instance_service principal with an organisation_id is rejected by the database', function (): void {
    $organization = Organization::factory()->create();

    expect(fn () => Principal::query()->create([
        'type' => PrincipalTypeEnum::InstanceService,
        'user_id' => null,
        'organisation_id' => $organization->getKey(),
        'name' => 'Bad instance service',
    ]))->toThrow(QueryException::class);
});

test('a second instance_service principal is rejected by the database', function (): void {
    Principal::query()->create([
        'type' => PrincipalTypeEnum::InstanceService,
        'user_id' => null,
        'organisation_id' => null,
        'name' => 'Instance Admin API',
    ]);

    expect(fn () => Principal::query()->create([
        'type' => PrincipalTypeEnum::InstanceService,
        'user_id' => null,
        'organisation_id' => null,
        'name' => 'Duplicate instance service',
    ]))->toThrow(QueryException::class);
});

test('a user principal with an organisation_id is accepted', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $principal = Principal::query()->create([
        'type' => PrincipalTypeEnum::User,
        'user_id' => $user->getKey(),
        'organisation_id' => $organization->getKey(),
        'name' => 'Member',
    ]);

    expect($principal->organisation_id)->toBe($organization->getKey());
});

test('updating a user principal organisation_id to null is rejected by the database', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    $principal = Principal::query()->create([
        'type' => PrincipalTypeEnum::User,
        'user_id' => $user->getKey(),
        'organisation_id' => $organization->getKey(),
        'name' => 'Member',
    ]);

    expect(fn () => DB::table('principals')->where('id', $principal->getKey())->update([
        'organisation_id' => null,
    ]))->toThrow(QueryException::class);
});

test('flipping type to user while organisation_id stays null is rejected by the database', function (): void {
    $principal = Principal::query()->create([
        'type' => PrincipalTypeEnum::InstanceService,
        'user_id' => null,
        'organisation_id' => null,
        'name' => 'Instance Admin API',
    ]);

    expect(fn () => DB::table('principals')->where('id', $principal->getKey())->update([
        'type' => PrincipalTypeEnum::User->value,
    ]))->toThrow(QueryException::class);
});
