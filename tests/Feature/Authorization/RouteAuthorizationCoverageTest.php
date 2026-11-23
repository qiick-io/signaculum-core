<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateApiKeyMiddleware;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * Every route of the app, with every method it answers, is either refused to a signed-in user
 * who holds no permission and belongs to no organisation (403), or explicitly classified below.
 *
 * Authorization lives in each FormRequest, controller or action: the admin route group only
 * applies `auth` and `verified`. This test is what keeps a new route, or a new method on an
 * existing route, from shipping without that check. It does not look at URI prefixes:
 *
 * - a route behind the session (`auth`) must answer 403 to that user, on every method, unless
 *   it is listed in SESSION_ROUTES_ANY_USER_MAY_USE with the reason;
 * - a write route outside the session must sit behind an API key (and answer 401 without one)
 *   or be listed in WRITE_ROUTES_WITHOUT_SESSION with what guards it instead;
 * - a route parameter with no fixture fails the test (add one), and so does a stale list entry.
 */
const SESSION_ROUTES_ANY_USER_MAY_USE = [
    'GET dashboard' => 'the signed-in user\'s own dashboard',
    'GET email/verify' => 'own email verification',
    'GET email/verify/{id}/{hash}' => 'own email verification (signed link)',
    'POST email/verification-notification' => 'own email verification',
    'GET oauth/device/authorize' => 'device code the user types in to approve',
    'POST oauth/device/authorize' => 'device code the user types in to approve',
    'DELETE oauth/device/authorize' => 'device code the user types in to deny',
    'DELETE oauth/authorize' => 'denies the authorization request pending in this session',
    'POST oauth/token/refresh' => 'Passport: refreshes the signed-in user\'s own transient token',
    'GET oauth/userinfo' => 'bearer access token of the user it describes (OIDC Core §5.3)',
    'POST oauth/userinfo' => 'bearer access token of the user it describes (OIDC Core §5.3)',
    'GET organizations' => 'lists only the organisations the signed-in user belongs to',
    'POST organizations' => 'any user may create an organisation and becomes its owner',
    'POST invitations/{token}' => 'the invitation token is the credential',
    'GET passkeys/confirm/options' => 'confirms the signed-in user\'s own identity',
    'POST passkeys/confirm' => 'confirms the signed-in user\'s own identity',
    'GET user/confirm-password' => 'confirms the signed-in user\'s own password',
    'POST user/confirm-password' => 'confirms the signed-in user\'s own password',
    'GET user/confirmed-password-status' => 'confirms the signed-in user\'s own password',
    'GET user/passkeys/options' => 'own passkeys',
    'POST user/passkeys' => 'own passkeys',
    'DELETE user/passkeys/{passkey}' => 'own passkeys (scoped to the signed-in user)',
    'POST user/two-factor-authentication' => 'own two-factor authentication',
    'DELETE user/two-factor-authentication' => 'own two-factor authentication',
    'POST user/confirmed-two-factor-authentication' => 'own two-factor authentication',
    'GET user/two-factor-qr-code' => 'own two-factor authentication',
    'GET user/two-factor-secret-key' => 'own two-factor authentication',
    'GET user/two-factor-recovery-codes' => 'own two-factor authentication',
    'POST user/two-factor-recovery-codes' => 'own two-factor authentication',
    'GET settings/profile' => 'own profile',
    'PATCH settings/profile' => 'own profile',
    'DELETE settings/profile' => 'own account',
    'GET settings/profile/export' => 'own data export',
    'PATCH settings/locale' => 'own language',
    'PUT settings/password' => 'own password',
    'GET settings/security' => 'own security settings',
    'GET settings/sessions' => 'own browser sessions',
    'DELETE settings/sessions/others' => 'own browser sessions',
    'DELETE settings/sessions/{publicId}' => 'own browser sessions (scoped to the signed-in user)',
    'GET settings/connected-applications' => 'own consents',
    'DELETE settings/connected-applications/{clientId}' => 'own consents (scoped to the signed-in user)',
    'DELETE impersonation' => 'ends the impersonation held in this session',
    'POST logout' => 'ends the signed-in user\'s own session',
    'GET settings' => 'redirect to settings/profile',
    'POST settings' => 'redirect to settings/profile',
    'PUT settings' => 'redirect to settings/profile',
    'PATCH settings' => 'redirect to settings/profile',
    'DELETE settings' => 'redirect to settings/profile',
    'OPTIONS settings' => 'redirect to settings/profile',
    'QUERY settings' => 'redirect to settings/profile',
];

/**
 * "METHOD uri" => what guards it instead of the session.
 */
const WRITE_ROUTES_WITHOUT_SESSION = [
    'POST login' => 'guest: Fortify sign-in, throttled',
    'POST login/email-code' => 'guest: email sign-in code, throttled',
    'POST login/email-code/verify' => 'guest: email sign-in code, throttled',
    'POST two-factor-challenge' => 'guest: second factor of a pending sign-in, throttled',
    'POST passkeys/login' => 'guest: passkey sign-in, throttled',
    'POST register' => 'guest: registration',
    'POST forgot-password' => 'guest: password reset request, throttled',
    'POST reset-password' => 'guest: the reset token is the credential',
    'POST oauth/authorize' => 'OIDC Core §3.1.2.1 authorization request; consent approval requires a signed-in user',
    'POST oauth/token' => 'OAuth client authentication (RFC 6749 §3.2.1)',
    'POST oauth/par' => 'OAuth client authentication (RFC 9126 §2)',
    'POST oauth/revoke' => 'OAuth client authentication (RFC 7009 §2.1)',
    'POST oauth/introspect' => 'OAuth client authentication (RFC 7662 §2.1)',
    'POST oauth/device/code' => 'OAuth client identification (RFC 8628 §3.1)',
    'POST oauth/end-session' => 'RP-initiated logout: id_token_hint or a confirmation page',
    'POST oauth/end-session/confirm' => 'RP-initiated logout confirmation of this browser\'s session',
    'PUT storage/{path}' => 'Laravel local disk upload: the temporary signed URL is the credential',
    'POST /' => 'redirect to the dashboard or login',
    'PUT /' => 'redirect to the dashboard or login',
    'PATCH /' => 'redirect to the dashboard or login',
    'DELETE /' => 'redirect to the dashboard or login',
    'QUERY /' => 'redirect to the dashboard or login',
];

const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

/**
 * Every route, once per method it answers (HEAD is GET without a body).
 *
 * @return list<array{method: string, route: RoutingRoute}>
 */
function everyRouteMethod(): array
{
    $routeMethods = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $routeMethods[] = ['method' => $method, 'route' => $route];
        }
    }

    return $routeMethods;
}

function routeMethodKey(string $method, RoutingRoute $route): string
{
    return "{$method} {$route->uri()}";
}

function isBehindSession(RoutingRoute $route): bool
{
    return collect($route->gatherMiddleware())
        ->contains(fn (mixed $middleware): bool => $middleware === 'auth' || (is_string($middleware) && str_starts_with($middleware, 'auth:')));
}

function isBehindApiKey(RoutingRoute $route): bool
{
    return in_array(AuthenticateApiKeyMiddleware::class, $route->gatherMiddleware(), true);
}

beforeEach(function (): void {
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
    $this->group = UserGroup::factory()->create();
    $this->trashedGroup = UserGroup::factory()->create();
    $this->trashedGroup->delete();
    $application = createOidcClient();
    $apiKey = adminApiKey()['apiKey'];
    $webhook = existingWebhook();
    $target = User::factory()->create();

    // Organisation routes take their member as `user`; every other route any user.
    $this->fixturesByParameter = [
        'user' => fn (RoutingRoute $route): User => str_starts_with($route->uri(), 'organizations/') ? $owner : $target,
        'userId' => fn (): int => $owner->getKey(),
        'application' => fn (): Model => $application,
        'group' => fn (RoutingRoute $route): Model => str_ends_with($route->uri(), '/restore') || str_ends_with($route->uri(), '/force')
            ? $this->trashedGroup
            : $this->group,
        'apiKey' => fn (): Model => $apiKey,
        'organization' => fn (): Model => $organization,
        'invitation' => fn (): Model => $invitation,
        'webhook' => fn (): Model => $webhook,
    ];
    $this->payloadsByRouteName = [
        'admin.groups.bulk' => ['action' => 'force_delete', 'ids' => [$this->group->getKey()]],
    ];
});

/**
 * The route's URI with every parameter replaced by its fixture, or null when one has none.
 *
 * @param  array<string, Closure(RoutingRoute): (Model|int|string)>  $fixturesByParameter
 */
function routeUrl(RoutingRoute $route, array $fixturesByParameter): ?string
{
    $uri = $route->uri();

    foreach ($route->parameterNames() as $parameterName) {
        if (! array_key_exists($parameterName, $fixturesByParameter)) {
            return null;
        }

        $fixture = $fixturesByParameter[$parameterName]($route);
        $value = $fixture instanceof Model
            ? $fixture->getAttribute($route->bindingFieldFor($parameterName) ?? $fixture->getRouteKeyName())
            : $fixture;

        $uri = preg_replace('/\{'.$parameterName.'(:[^}]+)?\}/', (string) $value, $uri);
    }

    return '/'.ltrim($uri, '/');
}

it('refuses every session route, on every method, to a user without permissions', function (): void {
    $nobody = User::factory()->create();
    $unexpectedResponses = [];
    $testedCount = 0;

    foreach (everyRouteMethod() as ['method' => $method, 'route' => $route]) {
        if (! isBehindSession($route) || array_key_exists(routeMethodKey($method, $route), SESSION_ROUTES_ANY_USER_MAY_USE)) {
            continue;
        }

        $url = routeUrl($route, $this->fixturesByParameter);

        if ($url === null) {
            $unexpectedResponses[] = routeMethodKey($method, $route).': a route parameter has no fixture';

            continue;
        }

        $status = $this->actingAs($nobody)
            ->call($method, $url, $this->payloadsByRouteName[$route->getName()] ?? [])
            ->getStatusCode();
        $testedCount++;

        if ($status !== 403) {
            $unexpectedResponses[] = routeMethodKey($method, $route).": {$status}";
        }
    }

    expect($unexpectedResponses)->toBe([])
        ->and($testedCount)->toBeGreaterThan(50)
        ->and($this->group->fresh())->not->toBeNull()
        ->and($this->trashedGroup->fresh()?->trashed())->toBeTrue();
});

it('classifies every write route outside the session, and refuses API routes without a key', function (): void {
    $unclassifiedRoutes = [];

    foreach (everyRouteMethod() as ['method' => $method, 'route' => $route]) {
        $key = routeMethodKey($method, $route);

        if (in_array($method, READ_METHODS, true) || isBehindSession($route) || array_key_exists($key, WRITE_ROUTES_WITHOUT_SESSION)) {
            continue;
        }

        if (! isBehindApiKey($route)) {
            $unclassifiedRoutes[] = "{$key}: neither session, API key nor listed";

            continue;
        }

        $url = routeUrl($route, $this->fixturesByParameter);
        $status = $url === null ? null : $this->call($method, $url)->getStatusCode();

        if ($status !== 401) {
            $unclassifiedRoutes[] = "{$key}: ".($status ?? 'a route parameter has no fixture');
        }
    }

    expect($unclassifiedRoutes)->toBe([]);
});

it('lists only routes and methods that still exist', function (): void {
    $existingKeys = collect(everyRouteMethod())
        ->map(fn (array $routeMethod): string => routeMethodKey($routeMethod['method'], $routeMethod['route']))
        ->all();

    expect(array_values(array_diff(array_keys(SESSION_ROUTES_ANY_USER_MAY_USE), $existingKeys)))->toBe([])
        ->and(array_values(array_diff(array_keys(WRITE_ROUTES_WITHOUT_SESSION), $existingKeys)))->toBe([]);
});
