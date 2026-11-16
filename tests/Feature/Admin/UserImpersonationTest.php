<?php

declare(strict_types=1);

use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Services\ImpersonationService;
use Spatie\Activitylog\Models\Activity;

/**
 * Instance-admin impersonation with audit (#66).
 */
function impersonationAdmin(array $attributes = []): User
{
    return User::factory()->instanceAdmin()->create($attributes);
}

test('a non admin cannot start impersonation', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($actor)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertForbidden();
});

test('an instance admin cannot impersonate another instance admin', function () {
    $admin = impersonationAdmin();
    $otherAdmin = impersonationAdmin();

    $this->actingAs($admin)
        ->post(route('admin.users.impersonation.store', $otherAdmin))
        ->assertForbidden();
});

test('an instance admin cannot impersonate themselves', function () {
    $admin = impersonationAdmin();

    $this->actingAs($admin)
        ->post(route('admin.users.impersonation.store', $admin))
        ->assertForbidden();
});

test('an instance admin cannot impersonate an inactive user', function () {
    $admin = impersonationAdmin();
    $target = User::factory()->create(['is_active' => false]);

    $this->actingAs($admin)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertForbidden();
});

test('an instance admin starts and stops impersonation with audit entries', function () {
    $admin = impersonationAdmin(['first_name' => 'Ada', 'last_name' => 'Admin']);
    $target = User::factory()->create(['first_name' => 'Tim', 'last_name' => 'Target']);

    $this->actingAs($admin)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($target);
    expect(session(ImpersonationService::SESSION_ACTOR_ID))->toBe($admin->id);

    expect(Activity::query()->where('event', ActivityEventEnum::UserImpersonationStarted->value)->count())->toBe(1);

    $this->delete(route('impersonation.destroy'))
        ->assertRedirect(route('admin.users.index'));

    $this->assertAuthenticatedAs($admin);
    expect(session()->has(ImpersonationService::SESSION_ACTOR_ID))->toBeFalse();

    expect(Activity::query()->where('event', ActivityEventEnum::UserImpersonationStopped->value)->count())->toBe(1);
});

test('expired impersonation restores the actor on the next request', function () {
    $admin = impersonationAdmin();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.impersonation.store', $target))
        ->assertRedirect(route('dashboard'));

    session([ImpersonationService::SESSION_EXPIRES_AT => now()->subMinute()->getTimestamp()]);

    $this->get(route('dashboard'))
        ->assertRedirect(route('admin.users.index'));

    $this->assertAuthenticatedAs($admin);
    expect(session()->has(ImpersonationService::SESSION_ACTOR_ID))->toBeFalse();
    expect(Activity::query()->where('event', ActivityEventEnum::UserImpersonationExpired->value)->count())->toBe(1);
});

test('the users list exposes can_impersonate for eligible targets', function () {
    $admin = impersonationAdmin();
    $target = User::factory()->create();
    $otherAdmin = impersonationAdmin();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->has('users.data', 3)
            ->where('users.data', function ($rows) use ($admin, $target, $otherAdmin): bool {
                $byId = collect($rows)->keyBy('id');

                return $byId[$target->id]['can_impersonate'] === true
                    && $byId[$admin->id]['can_impersonate'] === false
                    && $byId[$otherAdmin->id]['can_impersonate'] === false;
            }));
});
