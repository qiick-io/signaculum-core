<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\WebhookEventEnum;
use App\Models\User;
use App\Models\Webhook;
use App\Services\SettingsRepositoryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Activity log (S6 / #46): auth events, model changes, settings keys, filters and retention.
 */
function activityAdmin(array $attributes = []): User
{
    return User::factory()->instanceAdmin()->create($attributes);
}

function latestActivity(string $event, ?string $subjectType = null): Activity
{
    return Activity::query()
        ->where('event', $event)
        ->when($subjectType !== null, fn ($query) => $query->where('subject_type', $subjectType))
        ->latest('id')
        ->firstOrFail();
}

it('keeps the activity log behind instance admin', function () {
    $this->get(route('admin.activity-logs.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create())
        ->get(route('admin.activity-logs.index'))
        ->assertForbidden();
});

it('shows the activity log to instance admins', function () {
    $this->actingAs(activityAdmin())->get(route('admin.activity-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/activity-logs/index')
            ->has('activityLogs.data')
            ->where('logNames', ['default', 'auth', 'settings', 'ldap'])
            ->where('events', fn ($events) => collect($events)->contains('settings_updated'))
            ->where('auth.canShowActivityLogs', true));
});

it('logs user creation and updates with the acting admin and never the password', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->post(route('admin.users.store'), [
        'first_name' => 'Grace',
        'last_name' => 'Hopper',
        'email' => 'grace@example.com',
        'password' => 'correct-horse-battery',
    ])->assertRedirect(route('admin.users.index'));

    $user = User::query()->where('email', 'grace@example.com')->sole();
    $created = latestActivity('created', $user->getMorphClass());

    expect($created->causer_id)->toBe((string) $admin->id)
        ->and($created->subject_id)->toBe((string) $user->id)
        ->and($created->attribute_changes['attributes']['email'] ?? null)->toBe('grace@example.com')
        ->and($created->attribute_changes['attributes'] ?? [])->not->toHaveKey('password');

    $this->actingAs($admin)->put(route('admin.users.update', $user), [
        'first_name' => 'Grace',
        'last_name' => 'Murray',
        'email' => 'grace@example.com',
        'password' => '',
    ])->assertRedirect(route('admin.users.index'));

    $updated = latestActivity('updated', $user->getMorphClass());

    expect($updated->causer_id)->toBe((string) $admin->id)
        ->and($updated->attribute_changes['attributes']['last_name'] ?? null)->toBe('Murray')
        ->and(json_encode($updated->attribute_changes))->not->toContain('password');
});

it('excludes a password-only change from the activity log', function () {
    $admin = activityAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin);

    $user->update([
        'first_name' => 'Renamed',
        'password' => Hash::make('new-secret-password'),
    ]);

    $activity = latestActivity('updated', $user->getMorphClass());
    $encoded = json_encode($activity->attribute_changes?->toArray() ?? []);

    expect($encoded)->not->toContain('new-secret-password')
        ->and($encoded)->not->toContain('password')
        ->and($activity->attribute_changes['attributes']['first_name'] ?? null)->toBe('Renamed');
});

it('logs logins, logouts and failed logins without the password', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'wrong-password']);

    $failed = latestActivity('failed');

    expect($failed->log_name)->toBe('auth')
        ->and($failed->causer_id)->toBeNull()
        ->and($failed->properties['identifier'] ?? null)->toBe('ada@example.com')
        ->and(json_encode($failed->properties))->not->toContain('wrong-password');

    $this->post(route('login.store'), ['email' => 'ada@example.com', 'password' => 'password'])
        ->assertRedirect();

    expect(latestActivity('login'))->causer_id->toBe((string) $user->id)->log_name->toBe('auth')
        ->and(latestActivity('login')->properties['ip'] ?? null)->not->toBeNull();

    $this->actingAs($user)->post(route('logout'));

    expect(latestActivity('logout')->causer_id)->toBe((string) $user->id);
});

it('stores and reads back the subject of every logged model, integer and UUID keys alike', function () {
    // Clients have UUID keys, the other three integer keys: the morph columns hold both.
    // A bigint column passed on SQLite only; MySQL, MariaDB and PostgreSQL refused the UUID.
    $admin = activityAdmin();
    $this->actingAs($admin);

    $loggedModels = [
        'user' => User::factory()->create(),
        'client' => createOidcClient(),
        'api key' => app(CreateApiKeyAction::class)
            ->execute($admin, new ApiKeyData('Morph check', [ApiKeyScopeEnum::UsersRead->value]))
            ->apiKey,
        'webhook' => Webhook::query()->create([
            'url' => 'https://hooks.example.com/signaculum',
            'secret' => 'test-signing-secret-value',
            'events' => [WebhookEventEnum::UserCreated->value],
            'is_active' => true,
        ]),
    ];

    foreach ($loggedModels as $modelLabel => $loggedModel) {
        $created = latestActivity('created', $loggedModel->getMorphClass());

        expect($created->subject_id)->toBe((string) $loggedModel->getKey(), $modelLabel)
            ->and($created->subject?->is($loggedModel))->toBeTrue($modelLabel)
            ->and($created->causer?->is($admin))->toBeTrue($modelLabel);
    }
});

it('shows each subject id with its model key type, integer or UUID', function () {
    $admin = activityAdmin();
    $this->actingAs($admin);

    $user = User::factory()->create();
    $client = createOidcClient();

    $this->get(route('admin.activity-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where(
            'activityLogs.data',
            fn ($rows) => collect($rows)->pluck('subject.id')->contains(fn ($subjectId) => $subjectId === $user->getKey())
                && collect($rows)->pluck('subject.id')->contains(fn ($subjectId) => $subjectId === $client->getKey()),
        ));
});

it('logs instance settings updates with keys only', function () {
    $admin = activityAdmin();

    $this->actingAs($admin)->put(route('instance.update'), [
        'app_name' => 'Logged HQ',
        'description' => 'Audit me',
        'report_bug_url' => '',
        'activity_retention_days' => 90,
    ])->assertRedirect(route('instance.edit'));

    $activity = latestActivity('settings_updated');

    expect($activity->log_name)->toBe('settings')
        ->and($activity->causer_id)->toBe((string) $admin->id)
        ->and($activity->properties['section'] ?? null)->toBe('general')
        ->and($activity->properties['keys'] ?? [])->toContain('app_name')
        ->and($activity->properties['keys'] ?? [])->toContain('activity_retention_days')
        ->and(json_encode($activity->properties))->not->toContain('Logged HQ');
});

it('logs consent grants and application access revocation without secrets', function () {
    $user = User::factory()->create();
    $client = createOidcClient();
    $pkce = pkcePair();

    $this->actingAs($user);
    $code = approveAuthorization($client, $pkce['challenge'])['code'];
    redeemCode($client, $code, $pkce['verifier'])->assertOk();

    $consent = latestActivity('consent_granted');

    expect($consent->causer_id)->toBe((string) $user->id)
        ->and($consent->log_name)->toBe('auth')
        ->and($consent->properties['client_id'] ?? null)->toBe($client->getKey())
        ->and($consent->properties['scopes'] ?? [])->toContain('openid');

    $this->delete(route('connected-applications.destroy', $client->getKey()))
        ->assertRedirect(route('connected-applications.index'));

    $revoked = latestActivity('token_revoked');

    expect($revoked->causer_id)->toBe((string) $user->id)
        ->and($revoked->properties['client_id'] ?? null)->toBe($client->getKey())
        ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain($code);
});

it('filters the activity log by user, action, log, date and search', function () {
    $owner = activityAdmin(['first_name' => 'Owner']);
    $other = activityAdmin(['first_name' => 'Other']);

    activity()->causedBy($owner)->event('settings_updated')->useLog('settings')->log('Instance settings updated');
    activity()->causedBy($other)->event('login')->useLog('auth')->log('User logged in');
    Carbon::setTestNow(now()->subDays(10));
    activity()->causedBy($other)->event('logout')->useLog('auth')->log('User logged out');
    Carbon::setTestNow();

    $cases = [
        [['user_id' => $other->id], ['User logged in', 'User logged out']],
        [['user_ids' => [$owner->id]], ['Instance settings updated']],
        [['event' => 'login'], ['User logged in']],
        [['log_name' => 'settings'], ['Instance settings updated']],
        [['log_name' => 'auth', 'date_from' => now()->subDay()->toDateString()], ['User logged in']],
        [['log_name' => 'auth', 'date_to' => now()->subDays(5)->toDateString()], ['User logged out']],
        [['search' => 'logged out'], ['User logged out']],
    ];

    foreach ($cases as [$filters, $expected]) {
        $this->actingAs($owner)->get(route('admin.activity-logs.index', $filters))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'activityLogs.data',
                    fn ($rows) => collect($rows)->pluck('description')->sort()->values()->all() === $expected,
                ));
    }
});

it('ignores an unknown action filter', function () {
    $this->actingAs(activityAdmin())->get(route('admin.activity-logs.index', ['event' => 'ai_prompt']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('filters.event', ''));
});

it('cleans the activity log with the retention setting', function () {
    Date::setTestNow('2026-11-05 12:00:00');
    app(SettingsRepositoryService::class)->save(['activity_retention_days' => 30]);

    activity()->log('recent');
    Activity::query()->latest('id')->first()->forceFill(['created_at' => now()->subDays(10)])->save();
    activity()->log('old');
    Activity::query()->latest('id')->first()->forceFill(['created_at' => now()->subDays(40)])->save();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduledEvent): bool => $scheduledEvent->description === 'activitylog:clean');

    expect($event)->not->toBeNull();

    $event->run(app());

    expect(Activity::query()->pluck('description')->all())->toBe(['recent']);
});
