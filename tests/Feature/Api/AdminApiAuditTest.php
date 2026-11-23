<?php

declare(strict_types=1);

use App\Actions\AddOrganizationMemberAction;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\ApiKey;
use App\Models\Principal;
use App\Models\User;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Spatie\Activitylog\Models\Activity;

/**
 * Every Admin API write is attributable (decision 2026-11-22): each activity entry it writes is
 * caused by the key's service account (the instance_service principal) and names the key's
 * public id and prefix and the admin who created the key.
 *
 * The write routes are read from the route table ({@see adminApiWriteRouteNames()}), never
 * from a list: a new route fails until {@see adminApiWriteCalls()} can call it, and each call
 * must reach its own route, write at least one entry, and give every entry the three fields.
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
        $organization = createOrganizationWithOwner(User::factory()->create(), ['slug' => 'audited-org-'.Str::lower(Str::random(8))]);
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
            $organization = createOrganizationWithOwner(User::factory()->create(), ['slug' => 'audited-org-'.Str::lower(Str::random(8))]);
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
 * Every write route an API key can reach, found in the route table (not in a list): any non-GET
 * route under `/api/` (whatever its version), and any non-GET route elsewhere that the API key
 * middleware guards, directly or through an alias or group.
 *
 * GET routes are left out: none writes today, and a read cannot be called without knowing what
 * it reads. The activity hook is global, so an entry a GET did write would still be attributed.
 *
 * @return list<string>
 */
function adminApiWriteRouteNames(): array
{
    $router = app('router');

    return collect(Router::getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => array_diff($route->methods(), ['GET', 'HEAD']) !== [])
        ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/')
            || collect($router->gatherRouteMiddleware($route))->contains(
                static fn (mixed $middleware): bool => is_string($middleware)
                    && str_starts_with($middleware, AuthenticateApiKeyMiddleware::class),
            ))
        ->map(static fn (Route $route): string => $route->getName() ?? "unnamed {$route->uri()}")
        ->sort()
        ->values()
        ->all();
}

/**
 * @return array{admin: User, plain: string, apiKey: ApiKey}
 */
function adminApiAuditKey(): array
{
    return adminApiKey([
        ApiKeyScopeEnum::UsersWrite->value,
        ApiKeyScopeEnum::OrganizationsWrite->value,
        ApiKeyScopeEnum::ApplicationsWrite->value,
    ]);
}

it('records the service account, the key and its creator for every entry every Admin API write logs', function (): void {
    $issued = adminApiAuditKey();
    $servicePrincipal = $issued['apiKey']->ownerPrincipal;
    $callsByRouteName = adminApiWriteCalls($issued['plain']);
    $writeRouteNames = adminApiWriteRouteNames();
    $matchedRouteNames = [];
    Event::listen(RouteMatched::class, static function (RouteMatched $event) use (&$matchedRouteNames): void {
        $matchedRouteNames[] = $event->route->getName();
    });

    expect($writeRouteNames)->not->toBeEmpty()
        ->and($servicePrincipal)->toBeInstanceOf(Principal::class);

    foreach ($writeRouteNames as $routeName) {
        expect(array_key_exists($routeName, $callsByRouteName))
            ->toBeTrue("{$routeName} is an Admin API write route: add a call for it to adminApiWriteCalls()");

        $call = $callsByRouteName[$routeName]();
        $lastActivityIdBefore = (int) Activity::query()->max('id');
        $matchedRouteNames = [];

        $call()->assertSuccessful();

        $entries = Activity::query()->where('id', '>', $lastActivityIdBefore)->orderBy('id')->get();

        expect($matchedRouteNames)->toBe([$routeName], "the call for {$routeName} must request that route")
            ->and($entries)->not->toBeEmpty("{$routeName} wrote no activity entry");

        foreach ($entries as $entry) {
            $context = "{$routeName}: entry '{$entry->description}'";

            expect($entry->causer_type)->toBe($servicePrincipal->getMorphClass(), $context)
                ->and((string) $entry->causer_id)->toBe((string) $servicePrincipal->getKey(), $context)
                ->and($entry->properties?->get('api_key_id'))->toBe($issued['apiKey']->uuid, $context)
                ->and($entry->properties?->get('api_key_prefix'))->toBe($issued['apiKey']->key_prefix, $context)
                ->and($entry->properties?->get('api_key_created_by'))->toBe($issued['admin']->getKey(), $context);
        }
    }
});

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

it('lists the Admin API actions of a key when the activity log is filtered by its creator', function (): void {
    ['admin' => $creator, 'plain' => $plainTextToken] = adminApiAuditKey();
    $otherAdmin = User::factory()->instanceAdmin()->create();
    adminApiWriteCalls($plainTextToken)['api.v1.organizations.store']()()->assertCreated();
    $apiEntryIds = Activity::query()->where('causer_type', (new Principal)->getMorphClass())->pluck('id')->all();

    $filteredEntryIds = static fn (User $filterUser): array => collect(
        test()->actingAs($creator)->get(route('admin.activity-logs.index', ['user_ids' => [$filterUser->id]]))
            ->assertOk()
            ->viewData('page')['props']['activityLogs']['data'],
    )->pluck('id')->all();

    expect($apiEntryIds)->not->toBeEmpty()
        ->and(array_intersect($apiEntryIds, $filteredEntryIds($creator)))->toEqualCanonicalizing($apiEntryIds)
        ->and(array_intersect($apiEntryIds, $filteredEntryIds($otherAdmin)))->toBe([]);
});

it('finds a write route an API key can reach outside /api/v1', function (): void {
    Router::post('api/v2/probe', static fn () => null)->name('probe.api-v2');
    Router::post('internal/probe', static fn () => null)->middleware(AuthenticateApiKeyMiddleware::class)->name('probe.guarded');
    Router::get('internal/probe', static fn () => null)->middleware(AuthenticateApiKeyMiddleware::class)->name('probe.read');
    Router::post('internal/session-only', static fn () => null)->name('probe.session');
    Router::getRoutes()->refreshNameLookups();

    expect(adminApiWriteRouteNames())
        ->toContain('probe.api-v2', 'probe.guarded', 'api.v1.users.store')
        ->not->toContain('probe.read', 'probe.session', 'api.v1.users.index');
});
