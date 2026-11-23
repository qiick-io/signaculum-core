<?php

declare(strict_types=1);

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Every instance-admin, instance-settings and organisation route refuses a verified user who
 * holds no permission and belongs to no organisation.
 *
 * The admin route group only applies `auth` and `verified`: each route authorizes in its
 * FormRequest, controller or action. This test is what keeps a new route from shipping without
 * that check. A route parameter with no fixture below fails the test (add one), and a route that
 * is open by design must be listed in ROUTES_OPEN_BY_DESIGN with its reason.
 */
const GUARDED_URI_PREFIXES = ['admin/', 'settings/instance', 'settings/appearance', 'organizations'];

/**
 * Route name => why a user without permissions may reach it.
 */
const ROUTES_OPEN_BY_DESIGN = [
    'organizations.index' => 'lists only the organisations the signed-in user belongs to',
    'organizations.store' => 'any user may create an organisation and becomes its owner',
    'organizations.branding.show' => 'public logo asset, shown on the sign-in page before login',
];

/**
 * @return list<RoutingRoute>
 */
function guardedRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), GUARDED_URI_PREFIXES))
        ->values()
        ->all();
}

it('refuses every guarded route to a user without permissions', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner);
    $invitation = OrganizationInvitation::query()->create([
        'organization_id' => $organization->getKey(),
        'email' => 'invitee@example.com',
        'roles' => ['member'],
        'token_hash' => str_repeat('a', 64),
        'invited_by_user_id' => $owner->getKey(),
        'expires_at' => now()->addDay(),
    ]);
    $group = UserGroup::factory()->create();
    $trashedGroup = UserGroup::factory()->create();
    $trashedGroup->delete();

    // The organisation member is the `user` of the member routes; any other user elsewhere.
    $fixturesByParameter = [
        'user' => fn (RoutingRoute $route): int => str_starts_with($route->uri(), 'organizations/')
            ? $owner->getKey()
            : User::factory()->create()->getKey(),
        'application' => fn (): string => createOidcClient()->getKey(),
        'group' => fn (RoutingRoute $route): int => Str::endsWith($route->uri(), ['/restore', '/force'])
            ? $trashedGroup->getKey()
            : $group->getKey(),
        'apiKey' => fn (): string => adminApiKey()['apiKey']->getRouteKey(),
        'organization' => fn (): string => $organization->getRouteKey(),
        'invitation' => fn (): int => $invitation->getKey(),
        'webhook' => fn (): string => existingWebhook()->getRouteKey(),
    ];
    $payloadsByRouteName = [
        'admin.groups.bulk' => ['action' => 'force_delete', 'ids' => [$group->getKey()]],
    ];

    $nobody = User::factory()->create();
    $unexpectedResponses = [];

    foreach (guardedRoutes() as $route) {
        if (array_key_exists((string) $route->getName(), ROUTES_OPEN_BY_DESIGN)) {
            continue;
        }

        $method = $route->methods()[0];
        $uri = $route->uri();

        foreach ($route->parameterNames() as $parameterName) {
            if (! array_key_exists($parameterName, $fixturesByParameter)) {
                $unexpectedResponses[] = "{$method} {$uri}: no fixture for {{$parameterName}}";

                continue 2;
            }

            $uri = str_replace("{{$parameterName}}", (string) $fixturesByParameter[$parameterName]($route), $uri);
        }

        $status = $this->actingAs($nobody)
            ->call($method, "/{$uri}", $payloadsByRouteName[$route->getName()] ?? [])
            ->getStatusCode();

        if ($status !== 403) {
            $unexpectedResponses[] = "{$method} {$route->uri()}: {$status}";
        }
    }

    expect($unexpectedResponses)->toBe([])
        ->and($group->fresh())->not->toBeNull()
        ->and(UserGroup::onlyTrashed()->whereKey($trashedGroup->getKey())->exists())->toBeTrue();
});

it('lists only open-by-design routes that still exist', function (): void {
    $guardedRouteNames = collect(guardedRoutes())->map(fn (RoutingRoute $route): ?string => $route->getName());

    expect(array_diff(array_keys(ROUTES_OPEN_BY_DESIGN), $guardedRouteNames->all()))->toBe([]);
});

it('covers a meaningful set of routes', function (): void {
    // Guards against a prefix typo that would silently select nothing.
    expect(count(guardedRoutes()))->toBeGreaterThan(50);
});
