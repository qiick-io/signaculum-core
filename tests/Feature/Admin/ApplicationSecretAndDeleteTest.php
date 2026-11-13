<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Application secret show-once / regenerate, typed delete, and strict redirect URIs (#26).
 */
function applicationAdmin(array $attributes = []): User
{
    return User::factory()->instanceAdmin()->create($attributes);
}

test('creating a confidential application flashes the plain secret once', function () {
    $admin = applicationAdmin();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'Secret App',
            'redirect_uris' => 'https://app.example.test/callback',
            'is_confidential' => true,
            'is_first_party' => false,
        ])
        ->assertRedirect(route('admin.applications.index'));

    $this->actingAs($admin)
        ->get(route('admin.applications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plainTextSecret', fn ($secret): bool => is_string($secret) && $secret !== '')
            ->has('applications.data.0.discovery_url')
            ->has('applications.data.0.configure_command'));

    $this->actingAs($admin)
        ->get(route('admin.applications.index'))
        ->assertInertia(fn (Assert $page) => $page->where('plainTextSecret', null));
});

test('an instance admin regenerates a confidential client secret', function () {
    $admin = applicationAdmin();
    $client = createOidcClient();
    $previousSecret = $client->secret;

    $this->actingAs($admin)
        ->put(route('admin.applications.secret.update', $client))
        ->assertRedirect(route('admin.applications.index'));

    $client->refresh();

    expect($client->secret)->not->toBe($previousSecret)
        ->and($client->confidential())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.applications.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('plainTextSecret', fn ($secret): bool => is_string($secret) && $secret !== ''));
});

test('regenerating a public client secret is refused', function () {
    $admin = applicationAdmin();
    $client = createOidcClient(isConfidential: false);

    $this->actingAs($admin)
        ->put(route('admin.applications.secret.update', $client))
        ->assertForbidden();
});

test('an instance admin deletes an application with a typed name confirmation', function () {
    $admin = applicationAdmin();
    $client = createOidcClient();

    $this->actingAs($admin)
        ->delete(route('admin.applications.destroy', $client), [
            'confirmation' => 'wrong-name',
        ])
        ->assertSessionHasErrors('confirmation');

    $this->actingAs($admin)
        ->delete(route('admin.applications.destroy', $client), [
            'confirmation' => $client->name,
        ])
        ->assertRedirect(route('admin.applications.index'));

    expect(Client::query()->whereKey($client->getKey())->value('revoked'))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.applications.index'))
        ->assertInertia(fn (Assert $page) => $page->has('applications.data', 0));
});

test('a non admin cannot regenerate or delete applications', function () {
    $user = User::factory()->create();
    $client = createOidcClient();

    $this->actingAs($user)->put(route('admin.applications.secret.update', $client))->assertForbidden();
    $this->actingAs($user)->delete(route('admin.applications.destroy', $client), [
        'confirmation' => $client->name,
    ])->assertForbidden();
});

test('redirect uris must be https except localhost and must not contain fragments', function () {
    $admin = applicationAdmin();

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'Bad URIs',
            'redirect_uris' => "http://evil.example.test/callback\nhttps://ok.example.test/cb#fragment",
            'is_confidential' => true,
            'is_first_party' => false,
        ])
        ->assertSessionHasErrors('redirect_uris');

    $this->actingAs($admin)
        ->post(route('admin.applications.store'), [
            'name' => 'Localhost OK',
            'redirect_uris' => "http://localhost:3000/callback\nhttp://127.0.0.1/callback\nhttps://app.example.test/cb",
            'is_confidential' => true,
            'is_first_party' => false,
        ])
        ->assertRedirect(route('admin.applications.index'));
});
