<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;

/**
 * Admin applications list and create/edit drawer (#25). Secret regenerate/delete are #26.
 */
function instanceAdminForApplications(array $attributes = []): User
{
    return User::factory()->instanceAdmin()->create($attributes);
}

/**
 * @return array<string, mixed>
 */
function applicationFormPayload(array $overrides = []): array
{
    return [
        'name' => 'Externa',
        'redirect_uris' => "https://externa.test/auth/oidc/callback\nhttps://externa.test/auth/oidc/silent",
        'is_confidential' => true,
        'is_first_party' => true,
        'is_client_credentials_enabled' => false,
        'is_device_authorization_enabled' => false,
        'is_pushed_authorization_required' => false,
        'frontchannel_logout_uri' => '',
        'is_frontchannel_logout_session_required' => false,
        ...$overrides,
    ];
}

test('a non admin cannot open the applications list or mutate applications', function () {
    $user = User::factory()->create();
    $client = createOidcClient();

    $this->actingAs($user)->get(route('admin.applications.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.applications.store'), applicationFormPayload())->assertForbidden();
    $this->actingAs($user)->put(route('admin.applications.update', $client), applicationFormPayload([
        'name' => 'Hijacked',
    ]))->assertForbidden();
});

test('an instance admin lists applications with the inertia page', function () {
    $admin = instanceAdminForApplications();
    createOidcClient();

    $this->actingAs($admin)->get(route('admin.applications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/applications/index')
            ->has('applications.data', 1)
            ->where('applications.data.0.name', 'Example app')
            ->where('applications.data.0.is_confidential', true)
            ->where('applications.data.0.is_first_party', false));
});

test('the applications list searches sorts and paginates', function () {
    $admin = instanceAdminForApplications();
    app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Alpha App',
        [TEST_REDIRECT_URI],
        true,
    );
    app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Zeta App',
        [TEST_REDIRECT_URI],
        false,
    );

    $this->actingAs($admin)->get(route('admin.applications.index', [
        'search' => 'Alpha',
        'sort' => 'name',
        'direction' => 'asc',
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('applications.data', 1)
        ->where('applications.data.0.name', 'Alpha App')
        ->where('filters.search', 'Alpha')
        ->where('filters.sort', 'name')
        ->where('filters.direction', 'asc'));

    foreach (range(1, 12) as $index) {
        app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            "Bulk {$index}",
            [TEST_REDIRECT_URI],
            true,
        );
    }

    $this->actingAs($admin)->get(route('admin.applications.index', [
        'sort' => 'name',
        'direction' => 'asc',
        'per_page' => 10,
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('applications.data', 10)
        ->where('applications.meta.per_page', 10)
        ->where('applications.meta.last_page', 2));
});

test('an instance admin creates a confidential first-party application', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), applicationFormPayload())
        ->assertRedirect(route('admin.applications.index'));

    $client = Client::query()->where('name', 'Externa')->sole();

    expect($client->redirect_uris)->toBe([
        'https://externa.test/auth/oidc/callback',
        'https://externa.test/auth/oidc/silent',
    ])
        ->and($client->confidential())->toBeTrue()
        ->and($client->is_first_party)->toBeTrue()
        ->and($client->skipsAuthorization($admin, []))->toBeTrue()
        ->and($client->hasGrantType('authorization_code'))->toBeTrue()
        ->and($client->hasGrantType('client_credentials'))->toBeFalse();
});

test('an instance admin opts a confidential application into client credentials', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), applicationFormPayload([
            'name' => 'Job runner',
            'is_client_credentials_enabled' => true,
        ]))
        ->assertRedirect(route('admin.applications.index'));

    $client = Client::query()->where('name', 'Job runner')->sole();

    expect($client->hasGrantType('client_credentials'))->toBeTrue()
        ->and($client->hasGrantType('authorization_code'))->toBeTrue();
});

test('client credentials cannot be enabled on a public application', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), applicationFormPayload([
            'is_confidential' => false,
            'is_first_party' => false,
            'is_client_credentials_enabled' => true,
        ]))
        ->assertSessionHasErrors(['is_client_credentials_enabled']);
});

test('an instance admin requires pushed authorization requests per application', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), applicationFormPayload([
            'name' => 'High assurance',
            'is_pushed_authorization_required' => true,
        ]))
        ->assertRedirect(route('admin.applications.index'));

    $client = Client::query()->where('name', 'High assurance')->sole();

    expect($client->requiresPushedAuthorizationRequests())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.applications.index'))
        ->assertInertia(fn (Assert $page) => $page->where(
            'applications.data.0.is_pushed_authorization_required',
            true,
        ));

    $this->actingAs($admin)
        ->put(route('admin.applications.update', $client), applicationFormPayload([
            'name' => 'High assurance',
            'redirect_uris' => 'https://externa.test/auth/oidc/callback',
            'is_pushed_authorization_required' => false,
        ]))
        ->assertRedirect();

    expect($client->refresh()->requiresPushedAuthorizationRequests())->toBeFalse();
});

test('an instance admin creates a public application that does not skip consent', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), applicationFormPayload([
            'name' => 'Public SPA',
            'is_confidential' => false,
            'is_first_party' => false,
        ]))
        ->assertRedirect(route('admin.applications.index'));

    $client = Client::query()->where('name', 'Public SPA')->sole();

    expect($client->confidential())->toBeFalse()
        ->and($client->secret)->toBeNull()
        ->and($client->is_first_party)->toBeFalse()
        ->and($client->skipsAuthorization($admin, []))->toBeFalse();
});

test('creating an application validates name and redirect uris', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), applicationFormPayload([
            'name' => '',
            'redirect_uris' => '',
        ]))
        ->assertSessionHasErrors(['name', 'redirect_uris']);
});

test('an instance admin updates an application name redirect uris and flags', function () {
    $admin = instanceAdminForApplications();
    $client = createOidcClient();

    $this->actingAs($admin)
        ->put(route('admin.applications.update', $client), applicationFormPayload([
            'name' => 'Renamed',
            'redirect_uris' => 'https://renamed.test/callback',
            'is_confidential' => false,
            'is_first_party' => true,
        ]))
        ->assertRedirect(route('admin.applications.index'));

    $client->refresh();

    expect($client->name)->toBe('Renamed')
        ->and($client->redirect_uris)->toBe(['https://renamed.test/callback'])
        ->and($client->confidential())->toBeFalse()
        ->and($client->is_first_party)->toBeTrue();
});

test('a guest is redirected to login from the applications list', function () {
    $this->get(route('admin.applications.index'))->assertRedirect(route('login'));
});

test('first-party applications skip authorization consent', function () {
    $admin = instanceAdminForApplications();
    $client = createOidcClient();
    $client->forceFill(['is_first_party' => true])->save();

    expect($client->fresh()->skipsAuthorization($admin, []))->toBeTrue();

    $thirdParty = createOidcClient();

    expect($thirdParty->skipsAuthorization($admin, []))->toBeFalse();
});
