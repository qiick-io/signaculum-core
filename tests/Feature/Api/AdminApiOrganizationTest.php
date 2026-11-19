<?php

declare(strict_types=1);

use App\Enums\ApiKeyScopeEnum;
use App\Enums\OrganizationRoleEnum;
use App\Models\Organization;
use App\Models\OrganizationMembership;

/**
 * Admin REST API `/api/v1/organizations` (#180): refusals first, then the list, show and create.
 */
it('refuses every organizations endpoint without a key, and creates nothing', function (): void {
    $organization = Organization::factory()->create(['slug' => 'no-key-org']);

    foreach ([
        fn () => $this->getJson('/api/v1/organizations'),
        fn () => $this->getJson('/api/v1/organizations/'.$organization->slug),
        fn () => $this->postJson('/api/v1/organizations', ['name' => 'Smuggled']),
    ] as $call) {
        $call()
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Missing API key.', 'error' => 'invalid_token']);
    }

    expect(Organization::query()->where('name', 'Smuggled')->exists())->toBeFalse();
});

it('refuses a read key on the organizations create endpoint, and creates nothing', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);

    $this->withToken($plain)->postJson('/api/v1/organizations', ['name' => 'Read only org'])
        ->assertForbidden()
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="organizations:write"')
        ->assertExactJson([
            'message' => 'API key is missing the required scope.',
            'error' => 'insufficient_scope',
            'scope' => 'organizations:write',
        ]);

    expect(Organization::query()->where('name', 'Read only org')->count())->toBe(0);
});

it('refuses a key scoped to another resource on the organizations read endpoints', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value, ApiKeyScopeEnum::ApplicationsWrite->value]);
    $organization = Organization::factory()->create(['slug' => 'wrong-scope-org']);

    $this->withToken($plain)->getJson('/api/v1/organizations')
        ->assertForbidden()
        ->assertJsonPath('scope', 'organizations:read');

    $this->withToken($plain)->getJson('/api/v1/organizations/'.$organization->slug)
        ->assertForbidden()
        ->assertJsonPath('scope', 'organizations:read');
});

it('refuses a malformed organization create payload field by field', function (array $payload, array $invalidFields): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    $organizationsBefore = Organization::query()->count();

    $this->withToken($plain)->postJson('/api/v1/organizations', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($invalidFields);

    expect(Organization::query()->count())->toBe($organizationsBefore);
})->with([
    'no name' => [[], ['name']],
    'empty name' => [['name' => ''], ['name']],
    'name too long' => [['name' => str_repeat('a', 256)], ['name']],
    'name is an array' => [['name' => ['Acme']], ['name']],
    'slug with spaces' => [['name' => 'Acme', 'slug' => 'not a slug'], ['slug']],
    'slug too long' => [['name' => 'Acme', 'slug' => str_repeat('a', 256)], ['slug']],
]);

it('refuses a slug another organization already holds', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    Organization::factory()->create(['slug' => 'taken-slug']);

    $this->withToken($plain)->postJson('/api/v1/organizations', ['name' => 'Second', 'slug' => 'taken-slug'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);

    expect(Organization::query()->where('slug', 'taken-slug')->count())->toBe(1);
});

it('answers 404 for an organization slug that does not exist', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);

    $this->withToken($plain)->getJson('/api/v1/organizations/no-such-organization')->assertNotFound();
});

it('lists organizations alphabetically with the documented resource shape', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);
    Organization::factory()->create(['name' => 'Zulu Industries', 'slug' => 'zulu']);
    $first = Organization::factory()->create(['name' => 'Acme Corp', 'slug' => 'acme']);

    $this->withToken($plain)->getJson('/api/v1/organizations')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->getKey())
        ->assertJsonPath('data.0.name', 'Acme Corp')
        ->assertJsonPath('data.0.slug', 'acme')
        ->assertJsonPath('data.1.name', 'Zulu Industries')
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'created_at', 'updated_at']], 'links', 'meta']);
});

it('searches the organizations list by name and by slug', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);
    Organization::factory()->create(['name' => 'Northwind Traders', 'slug' => 'northwind']);
    Organization::factory()->create(['name' => 'Southwind Freight', 'slug' => 'southwind']);
    Organization::factory()->create(['name' => 'Unrelated', 'slug' => 'other-co']);

    $this->withToken($plain)->getJson('/api/v1/organizations?search=Northwind')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'northwind');

    $this->withToken($plain)->getJson('/api/v1/organizations?search=wind')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($plain)->getJson('/api/v1/organizations?search=other-co')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Unrelated');

    // A blank search is not a filter.
    $this->withToken($plain)->getJson('/api/v1/organizations?search=%20')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('clamps the organizations per_page to the configured maximum', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);
    config(['api.max_per_page' => 2]);
    Organization::factory()->count(3)->create();

    $this->withToken($plain)->getJson('/api/v1/organizations?per_page=99')
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonCount(2, 'data');
});

it('shows one organization', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);
    $organization = Organization::factory()->create(['name' => 'Shown Corp', 'slug' => 'shown-corp']);

    $this->withToken($plain)->getJson('/api/v1/organizations/shown-corp')
        ->assertOk()
        ->assertJsonPath('data.id', $organization->getKey())
        ->assertJsonPath('data.name', 'Shown Corp')
        ->assertJsonPath('data.slug', 'shown-corp');
});

it('creates an organization and makes the key owner its first owner', function (): void {
    ['plain' => $plain, 'admin' => $admin] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/organizations', ['name' => 'Created Corp', 'slug' => 'created-corp'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Created Corp')
        ->assertJsonPath('data.slug', 'created-corp');

    $organization = Organization::query()->where('slug', 'created-corp')->firstOrFail();
    $membership = OrganizationMembership::query()
        ->where('organization_id', $organization->getKey())
        ->where('user_id', $admin->getKey())
        ->first();

    expect($membership)->not->toBeNull()
        ->and($membership?->roles)->toContain(OrganizationRoleEnum::Owner->value);
});

it('derives the slug from the name when none is given', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);

    $this->withToken($plain)
        ->postJson('/api/v1/organizations', ['name' => 'Ada & Co Limited'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'ada-co-limited');
});

it('lets an organizations write key read as well', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsWrite->value]);
    Organization::factory()->create(['slug' => 'readable']);

    $this->withToken($plain)->getJson('/api/v1/organizations')->assertOk();
    $this->withToken($plain)->getJson('/api/v1/organizations/readable')->assertOk();
});

/*
 * #186: OrganizationData carries the MFA requirement, the organisation settings page
 * writes it and the middleware enforces it, but the Admin REST API resource left it
 * out — the same split that hid `is_device_authorization_enabled` on applications.
 * The API has no organisation update endpoint yet, so this is the read half.
 */
it('reports whether an organization requires MFA', function (): void {
    ['plain' => $plain] = adminApiKey([ApiKeyScopeEnum::OrganizationsRead->value]);
    $organization = Organization::factory()->create([
        'name' => 'Mfa Corp',
        'slug' => 'mfa-corp',
        'is_mfa_required' => true,
    ]);

    $this->withToken($plain)->getJson('/api/v1/organizations/'.$organization->slug)
        ->assertOk()
        ->assertJsonPath('data.is_mfa_required', true);

    $this->withToken($plain)->getJson('/api/v1/organizations')
        ->assertOk()
        ->assertJsonPath('data.0.is_mfa_required', true);
});
