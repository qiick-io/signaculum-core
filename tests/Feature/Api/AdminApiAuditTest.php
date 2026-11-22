<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Spatie\Activitylog\Models\Activity;

/**
 * Every Admin API write is attributable (decision 2026-11-22): each activity entry it writes is
 * caused by the key's service account (the instance_service principal) and names the key's
 * public id and prefix and the admin who created the key.
 *
 * {@see adminApiWriteCalls()} must list every write route of the Admin API: a new route that is
 * not listed fails 'covers every Admin API write route', and a listed one that writes no entry,
 * or an entry without the three fields, fails its own case.
 */

/**
 * One call per Admin API write route, keyed by route name. Each prepares what the call needs
 * (users, an organisation, an application) and returns the call itself, so the entries the
 * preparation writes are not mistaken for the call's own.
 *
 * @return array<string, Closure(): Closure(): TestResponse>
 */
function adminApiWriteCalls(string $plainTextToken): array
{
    $withKey = static fn () => test()->withToken($plainTextToken);
    $organizationWithMember = static function (): array {
        $organization = createOrganizationWithOwner(User::factory()->create(), ['slug' => 'audited-org']);
        $member = User::factory()->create();
        app(AddOrganizationMemberAction::class)->execute($organization, $member, [OrganizationRoleEnum::Member->value]);

        return [$organization, $member];
    };

    return [
        'api.v1.users.store' => static fn () => static fn () => $withKey()->postJson(route('api.v1.users.store'), [
            'first_name' => 'Audited',
            'email' => 'audited@example.test',
            'password' => 'C0rrect-Horse-Battery!',
        ]),
        'api.v1.users.update' => static function () use ($withKey): Closure {
            $user = User::factory()->create();

            return static fn () => $withKey()->patchJson(
                route('api.v1.users.update', $user->subject),
                ['first_name' => 'Renamed', 'email' => $user->email],
            );
        },
        'api.v1.users.deactivate' => static function () use ($withKey): Closure {
            $user = User::factory()->create();

            return static fn () => $withKey()->postJson(route('api.v1.users.deactivate', $user->subject));
        },
        'api.v1.users.reset-two-factor' => static function () use ($withKey): Closure {
            $user = User::factory()->withTwoFactor()->create();

            return static fn () => $withKey()->postJson(route('api.v1.users.reset-two-factor', $user->subject));
        },
        'api.v1.organizations.store' => static fn () => static fn () => $withKey()->postJson(route('api.v1.organizations.store'), [
            'name' => 'Audited organisation',
        ]),
        'api.v1.organizations.memberships.store' => static function () use ($withKey): Closure {
            $organization = createOrganizationWithOwner(User::factory()->create(), ['slug' => 'audited-org']);
            $newMember = User::factory()->create();

            return static fn () => $withKey()->postJson(
                route('api.v1.organizations.memberships.store', $organization),
                ['user_id' => $newMember->subject, 'roles' => [OrganizationRoleEnum::Member->value]],
            );
        },
        'api.v1.organizations.memberships.update' => static function () use ($withKey, $organizationWithMember): Closure {
            [$organization, $member] = $organizationWithMember();

            return static fn () => $withKey()->patchJson(
                route('api.v1.organizations.memberships.update', [$organization, $member->subject]),
                ['roles' => [OrganizationRoleEnum::Admin->value]],
            );
        },
        'api.v1.organizations.memberships.destroy' => static function () use ($withKey, $organizationWithMember): Closure {
            [$organization, $member] = $organizationWithMember();

            return static fn () => $withKey()->deleteJson(
                route('api.v1.organizations.memberships.destroy', [$organization, $member->subject]),
            );
        },
        'api.v1.applications.store' => static fn () => static fn () => $withKey()->postJson(route('api.v1.applications.store'), [
            'name' => 'Audited application',
            'redirect_uris' => ['https://audited.test/auth/oidc/callback'],
            'is_confidential' => true,
            'is_first_party' => false,
        ]),
        'api.v1.applications.rotate-secret' => static function () use ($withKey): Closure {
            $application = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
                'Audited app',
                ['https://audited.test/callback'],
            );

            return static fn () => $withKey()->postJson(route('api.v1.applications.rotate-secret', $application));
        },
    ];
}

/**
 * @return list<string>
 */
function adminApiWriteRouteNames(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => in_array(AuthenticateApiKeyMiddleware::class, $route->gatherMiddleware(), true)
            && array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->map(static fn (Route $route): string => (string) $route->getName())
        ->sort()
        ->values()
        ->all();
}

function adminApiAuditKey(): array
{
    return adminApiKey([
        ApiKeyScopeEnum::UsersWrite->value,
        ApiKeyScopeEnum::OrganizationsWrite->value,
        ApiKeyScopeEnum::ApplicationsWrite->value,
    ]);
}

it('covers every Admin API write route', function (): void {
    ['plain' => $plainTextToken] = adminApiAuditKey();
    $listedRouteNames = array_keys(adminApiWriteCalls($plainTextToken));
    sort($listedRouteNames);

    expect(adminApiWriteRouteNames())->not->toBeEmpty()->toBe($listedRouteNames);
});

it('records the service account, the key and its creator for every entry an Admin API write logs', function (string $routeName): void {
    /** @var array{admin: User, plain: string, apiKey: ApiKey} $issued */
    $issued = adminApiAuditKey();
    $call = adminApiWriteCalls($issued['plain'])[$routeName]();
    $lastActivityIdBefore = (int) Activity::query()->max('id');

    $call()->assertSuccessful();

    $entries = Activity::query()->where('id', '>', $lastActivityIdBefore)->orderBy('id')->get();
    $servicePrincipal = $issued['apiKey']->ownerPrincipal;

    expect($entries)->not->toBeEmpty("{$routeName} wrote no activity entry")
        ->and($servicePrincipal)->toBeInstanceOf(Principal::class);

    foreach ($entries as $entry) {
        $context = "{$routeName}: entry '{$entry->description}'";

        expect($entry->causer_type)->toBe($servicePrincipal->getMorphClass(), $context)
            ->and((string) $entry->causer_id)->toBe((string) $servicePrincipal->getKey(), $context)
            ->and($entry->properties?->get('api_key_id'))->toBe($issued['apiKey']->uuid, $context)
            ->and($entry->properties?->get('api_key_prefix'))->toBe($issued['apiKey']->key_prefix, $context)
            ->and($entry->properties?->get('api_key_created_by'))->toBe($issued['admin']->getKey(), $context);
    }
})->with([
    'create a user' => 'api.v1.users.store',
    'update a user' => 'api.v1.users.update',
    'deactivate a user' => 'api.v1.users.deactivate',
    'reset a user\'s two-factor' => 'api.v1.users.reset-two-factor',
    'create an organisation' => 'api.v1.organizations.store',
    'add a membership' => 'api.v1.organizations.memberships.store',
    'change a membership' => 'api.v1.organizations.memberships.update',
    'remove a membership' => 'api.v1.organizations.memberships.destroy',
    'create an application' => 'api.v1.applications.store',
    'rotate an application secret' => 'api.v1.applications.rotate-secret',
]);

it('keeps the signed-in admin as the causer outside the Admin API', function (): void {
    ['admin' => $admin] = adminApiAuditKey();

    $this->actingAs($admin)
        ->put(route('admin.users.update', User::factory()->create()), ['first_name' => 'Console', 'email' => 'console@example.test'])
        ->assertSessionHasNoErrors();

    $entry = Activity::query()->latest('id')->firstOrFail();

    expect((string) $entry->causer_id)->toBe((string) $admin->getKey())
        ->and($entry->causer_type)->toBe($admin->getMorphClass())
        ->and($entry->properties?->has('api_key_id'))->toBeFalse()
        ->and($entry->properties?->has('api_key_created_by'))->toBeFalse();
});

it('shows the service account as the causer in the activity log', function (): void {
    ['admin' => $admin, 'plain' => $plainTextToken, 'apiKey' => $apiKey] = adminApiAuditKey();
    adminApiWriteCalls($plainTextToken)['api.v1.organizations.store']()()->assertCreated();

    $this->actingAs($admin)
        ->get(route('admin.activity-logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activityLogs.data.0.causer', [
                'id' => $apiKey->owner_principal_id,
                'name' => 'Instance Admin API',
                'email' => null,
            ])
            ->where('activityLogs.data.0.properties.meta.api_key_prefix', $apiKey->key_prefix));
});
