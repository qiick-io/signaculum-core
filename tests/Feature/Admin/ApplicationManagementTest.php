<?php

declare(strict_types=1);

use App\Models\Activity;
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

test('an instance admin gets 404 for an application route key that is not a uuid', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->put(route('admin.applications.update', 'not-a-uuid'), applicationFormPayload())
        ->assertNotFound();
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

// OIDC RP-Initiated Logout 1.0 §2: the end-session endpoint only redirects to a post-logout URI registered on the client.
test('an instance admin registers post-logout redirect uris the end-session endpoint then honours', function () {
    $admin = instanceAdminForApplications();
    $client = createOidcClient();

    $this->actingAs($admin)
        ->put(route('admin.applications.update', $client), applicationFormPayload([
            'post_logout_redirect_uris' => "https://externa.test/auth/oidc/logged-out\nhttps://externa.test/\nhttps://externa.test/",
        ]))
        ->assertRedirect(route('admin.applications.index'));

    expect($client->refresh()->post_logout_redirect_uris)->toBe(['https://externa.test/auth/oidc/logged-out', 'https://externa.test/']);

    $this->get(route('admin.applications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('applications.data.0.post_logout_redirect_uris', ['https://externa.test/auth/oidc/logged-out', 'https://externa.test/']));

    auth()->guard('web')->logout();

    $this->get('/oauth/end-session?'.http_build_query([
        'client_id' => $client->getKey(),
        'post_logout_redirect_uri' => 'https://externa.test/auth/oidc/logged-out',
    ]))->assertRedirect('https://externa.test/auth/oidc/logged-out');
});

test('an instance admin creates an application with post-logout redirect uris and clears them', function () {
    $admin = instanceAdminForApplications();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), applicationFormPayload([
            'post_logout_redirect_uris' => 'https://externa.test/auth/oidc/logged-out',
        ]))
        ->assertRedirect(route('admin.applications.index'));

    $client = Client::query()->where('name', 'Externa')->sole();

    expect($client->post_logout_redirect_uris)->toBe(['https://externa.test/auth/oidc/logged-out']);

    $this->put(route('admin.applications.update', $client), applicationFormPayload(['post_logout_redirect_uris' => '']))
        ->assertRedirect(route('admin.applications.index'));

    expect($client->refresh()->post_logout_redirect_uris)->toBeNull();
});

// The same shape as redirect URIs (RedirectUriRule): matched exactly, so no wildcard, no fragment, https except loopback.
test('post-logout redirect uris follow the redirect uri rules', function (string $postLogoutRedirectUri) {
    $admin = instanceAdminForApplications();
    $client = createOidcClient();

    $this->actingAs($admin)
        ->put(route('admin.applications.update', $client), applicationFormPayload([
            'post_logout_redirect_uris' => "https://externa.test/auth/oidc/logged-out\n{$postLogoutRedirectUri}",
        ]))
        ->assertSessionHasErrors('post_logout_redirect_uris');

    expect($client->refresh()->post_logout_redirect_uris)->toBeNull();
})->with([
    'plain http' => 'http://externa.test/auth/oidc/logged-out',
    'fragment' => 'https://externa.test/auth/oidc/logged-out#done',
    'wildcard host' => 'https://*.externa.test/auth/oidc/logged-out',
    'relative' => '/auth/oidc/logged-out',
]);

test('loopback post-logout redirect uris may use http', function () {
    $admin = instanceAdminForApplications();
    $client = createOidcClient();

    $this->actingAs($admin)
        ->put(route('admin.applications.update', $client), applicationFormPayload([
            'post_logout_redirect_uris' => "http://localhost:8000/auth/oidc/logged-out\nhttp://127.0.0.1/auth/oidc/logged-out",
        ]))
        ->assertSessionHasNoErrors();

    expect($client->refresh()->post_logout_redirect_uris)->toHaveCount(2);
});

test('changing post-logout redirect uris is recorded in the activity log', function () {
    $admin = instanceAdminForApplications();
    $client = createOidcClient();

    $this->actingAs($admin)
        ->put(route('admin.applications.update', $client), applicationFormPayload([
            'post_logout_redirect_uris' => 'https://externa.test/auth/oidc/logged-out',
        ]))
        ->assertRedirect(route('admin.applications.index'));

    $activity = Activity::query()
        ->where('subject_type', $client->getMorphClass())
        ->where('subject_id', $client->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect((string) $activity->causer_id)->toBe((string) $admin->getKey())
        ->and($activity->attribute_changes?->toArray()['attributes']['post_logout_redirect_uris'] ?? null)
        ->toBe(['https://externa.test/auth/oidc/logged-out']);
});
