<?php

declare(strict_types=1);

use App\Http\Resources\ActivityLogResource;
use App\Models\Activity;
use App\Models\Principal;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;

/**
 * The activity log row as the console receives it: who caused it (a user, the Admin API service
 * account, or the system), what it touched and what it recorded, field by field.
 */
function activityLogRow(Activity $activity): array
{
    return (new ActivityLogResource($activity->load(['causer', 'subject'])))->toArray(request());
}

function subjectLabelOf(object $subject): string
{
    return (new ReflectionMethod(ActivityLogResource::class, 'subjectLabel'))
        ->invoke(new ActivityLogResource(new Activity), $subject);
}

beforeEach(function (): void {
    $this->freezeSecond();
});

it('shows a user causer, the subject and every recorded field', function (): void {
    $admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Admin', 'email' => 'ada@example.test']);
    $group = UserGroup::factory()->create(['name' => 'Operators']);

    activity()->causedBy($admin)->performedOn($group)->event('updated')->useLog('default')
        ->withProperties(['reason' => 'audit'])
        ->log('Group updated');

    $row = activityLogRow(Activity::query()->latest('id')->firstOrFail());

    expect($row['causer'])->toBe(['id' => $admin->id, 'name' => 'Ada Admin', 'email' => 'ada@example.test'])
        ->and($row['subject'])->toBe(['type' => 'UserGroup', 'id' => $group->id, 'label' => 'Operators'])
        ->and($row['event'])->toBe('updated')
        ->and($row['description'])->toBe('Group updated')
        ->and($row['log_name'])->toBe('default')
        ->and($row['created_at'])->toBe(now()->toIso8601String())
        ->and($row['changes'])->toBe([])
        // The activity hook records the request's address and agent on every entry.
        ->and($row['properties']['ip'])->toBe(request()->ip())->not->toBeNull()
        ->and($row['properties']['user_agent'])->toBe(request()->userAgent())->not->toBeNull()
        ->and($row['properties']['meta'])->toBe(['reason' => 'audit']);
});

it('shows the Admin API service account as the causer, without an email', function (): void {
    $principal = Principal::query()->create(['type' => 'instance_service', 'name' => 'Instance Admin API']);

    activity()->causedBy($principal)->event('created')->log('Created through the API');

    expect(activityLogRow(Activity::query()->latest('id')->firstOrFail())['causer'])
        ->toBe(['id' => $principal->id, 'name' => 'Instance Admin API', 'email' => null]);
});

it('shows no causer for a causer that is neither a user nor the service account', function (): void {
    activity()->causedBy(UserGroup::factory()->create())->log('Odd causer');

    expect(activityLogRow(Activity::query()->latest('id')->firstOrFail())['causer'])->toBeNull();
});

it('shows the attribute changes a model event recorded', function (): void {
    $group = UserGroup::factory()->create(['name' => 'Before']);
    $group->update(['name' => 'After']);

    $changes = activityLogRow(Activity::query()->where('event', 'updated')->latest('id')->firstOrFail())['changes'];

    expect($changes['attributes']['name'] ?? null)->toBe('After')
        ->and($changes['old']['name'] ?? null)->toBe('Before');
});

it('shows no causer, subject, date, changes or properties for a bare system entry', function (): void {
    DB::table('activity_log')->insert(['log_name' => 'default', 'description' => 'Bare entry']);

    $row = activityLogRow(Activity::query()->latest('id')->firstOrFail());

    expect($row['causer'])->toBeNull()
        ->and($row['subject'])->toBeNull()
        ->and($row['created_at'])->toBeNull()
        ->and($row['changes'])->toBe([])
        ->and($row['properties'])->toBe(['ip' => null, 'user_agent' => null, 'meta' => []]);
});

it('labels a subject by its trimmed name or url, and by its id when that is empty', function (): void {
    expect(subjectLabelOf((new UserGroup)->forceFill(['id' => 5, 'name' => '  Operators  '])))->toBe('Operators')
        ->and(subjectLabelOf((new UserGroup)->forceFill(['id' => 5, 'name' => '   '])))->toBe('#5')
        ->and(subjectLabelOf((new UserGroup)->forceFill(['id' => 6, 'name' => null])))->toBe('#6')
        ->and(subjectLabelOf((new Webhook)->forceFill(['id' => 7, 'url' => ' https://hooks.example.test/in '])))->toBe('https://hooks.example.test/in')
        ->and(subjectLabelOf((new Webhook)->forceFill(['id' => 8, 'url' => null])))->toBe('#8');
});

it('refuses to label a subject it has no arm for', function (): void {
    subjectLabelOf(new Principal);
})->throws(LogicException::class, 'No activity subject label for App\Models\Principal; add an arm in ActivityLogResource::subjectLabel().');
