<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Models\Organization;
use App\Models\User;
use App\Rules\ReservedOrganizationSlugRule;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Organisation slugs that clash with the app's paths, system names or are one or two
 * characters long are refused on every door (web create and edit, Admin REST API), also when
 * the slug is derived from the name (#255).
 */
dataset('reserved slugs', [
    'a route segment' => 'admin',
    'upper case' => 'ADMIN',
    'mixed case with a trailing separator' => 'Admin_',
    'an accented letter' => 'àdmin',
    'a dotted route segment' => 'well-known',
    'the OAuth endpoints' => 'oauth',
    'the API' => 'api',
    'sign-in' => 'login',
    'a public directory' => 'build',
    'a system name' => 'www',
    'support' => 'Support',
    'a planned protocol door' => 'scim',
    'two characters' => 'ab',
    'one character' => 'x',
]);

it('refuses a reserved slug on the create form', function (string $slug): void {
    $this->actingAs(User::factory()->create())
        ->post(route('organizations.store'), ['name' => 'Acme', 'slug' => $slug])
        ->assertSessionHasErrors('slug');

    expect(Organization::query()->exists())->toBeFalse();
})->with('reserved slugs');

it('refuses a reserved slug derived from the name', function (string $name): void {
    $this->actingAs(User::factory()->create())
        ->post(route('organizations.store'), ['name' => $name])
        ->assertSessionHasErrors('slug');

    expect(Organization::query()->exists())->toBeFalse();
})->with(['Admin', 'Settings', 'AB']);

it('refuses a slug derived from the name when another organisation has it', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('organizations.store'), ['name' => 'Acme Corp'])
        ->assertRedirect();

    $this->post(route('organizations.store'), ['name' => 'Acme corp'])
        ->assertSessionHasErrors('slug');

    expect(Organization::query()->count())->toBe(1);
});

it('accepts an ordinary slug of three characters or more', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('organizations.store'), ['name' => 'Acme', 'slug' => 'abc'])
        ->assertRedirect();

    expect(Organization::query()->where('slug', 'abc')->exists())->toBeTrue();
});

it('refuses a reserved slug through the Admin REST API', function (string $slug): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);

    $this->withToken($plain)
        ->postJson(route('api.v1.organizations.store'), ['name' => 'Acme', 'slug' => $slug])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');

    $this->withToken($plain)
        ->postJson(route('api.v1.organizations.store'), ['name' => $slug])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('slug');

    expect(Organization::query()->exists())->toBeFalse();
})->with(['admin', 'OAuth', 'xy']);

it('refuses renaming an organisation to a reserved slug, and keeps an older one editable', function (): void {
    $owner = User::factory()->create();
    $organization = createOrganizationWithOwner($owner, ['slug' => 'acme']);
    $legacy = createOrganizationWithOwner($owner, ['slug' => 'hq']);
    $this->actingAs($owner);

    $this->put(route('organizations.update', $organization), ['name' => 'Acme', 'slug' => 'Settings', 'is_mfa_required' => false])
        ->assertSessionHasErrors('slug');

    // A slug taken before it was reserved does not lock the organisation's other fields.
    $this->put(route('organizations.update', $legacy), ['name' => 'Head office', 'slug' => 'hq', 'is_mfa_required' => false])
        ->assertSessionHasNoErrors();

    expect($organization->fresh()?->slug)->toBe('acme')
        ->and($legacy->fresh()?->name)->toBe('Head office');
});

it('reserves every fixed name and the public file names', function (string $slug): void {
    expect(ReservedOrganizationSlugRule::isReserved($slug))->toBeTrue();
})->with([
    'www', 'mail', 'root', 'administrator', 'support', 'help', 'status', 'security',
    'mcp', 'saml', 'scim', 'ldap', 'oidc', 'auth', 'horizon', 'telescope', 'pulse', 'reverb',
    'app', 'sso', 'signaculum', 'qiick',
    // From public/: favicon.ico, robots.txt, the build directory.
    'favicon', 'robots', 'build',
]);

it('keeps ordinary slugs of three characters or more available', function (string $slug): void {
    expect(ReservedOrganizationSlugRule::isReserved($slug))->toBeFalse();
})->with(['abc', 'acme', 'faviconico', 'acme-admin']);

it('reserves the first path segment of every route', function (): void {
    $segments = collect(Route::getRoutes()->getRoutes())
        ->map(fn (RoutingRoute $route): string => Str::before($route->uri(), '/'))
        ->reject(fn (string $segment): bool => $segment === '' || str_starts_with($segment, '{'))
        ->unique();

    $acceptedSegments = $segments
        ->filter(fn (string $segment): bool => Validator::make(['slug' => $segment], ['slug' => [new ReservedOrganizationSlugRule]])->passes())
        ->values()
        ->all();

    expect($acceptedSegments)->toBe([])
        ->and($segments->count())->toBeGreaterThan(15);
});
