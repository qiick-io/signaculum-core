<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

beforeEach(function (): void {
    $this->withoutVite();

    Route::middleware('web')->get('/__test/http-error/{status}', function (int $status) {
        abort($status);
    });
});

test('a guest 404 on a web route redirects to login', function (): void {
    $this->get('/this-route-does-not-exist')
        ->assertRedirect(route('login'));
});

test('an authenticated 404 renders the inertia error page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error-page')
            ->where('status', 404)
            ->where('homeUrl', route('dashboard')));
});

test('an authenticated 403 renders the inertia error page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/__test/http-error/403')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error-page')
            ->where('status', 403));
});

test('a 503 renders the inertia error page for web requests', function (): void {
    $this->get('/__test/http-error/503')
        ->assertStatus(503)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error-page')
            ->where('status', 503));
});

test('json clients keep the default error payload', function (): void {
    $this->getJson('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('an authenticated 500 renders the inertia error page when debug is off', function (): void {
    config(['app.debug' => false]);

    $this->actingAs(User::factory()->create())
        ->get('/__test/http-error/500')
        ->assertStatus(500)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error-page')
            ->where('status', 500));
});

test('a guest branding 404 stays a real 404', function (): void {
    $this->get(route('branding.show', 'logo-dark'))
        ->assertNotFound();
});

test('a model-not-found 404 shares auth so the app layout can render', function (): void {
    $admin = User::factory()->instanceAdmin()->create();

    // Route matches (session runs) but binding fails before HandleInertiaRequests.
    Route::middleware('web')->get('/__test-users/{user}', function (User $user) {
        return response('ok');
    });

    $this->actingAs($admin)
        ->get('/__test-users/999999')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('errors/error-page')
            ->where('auth.user.id', $admin->id)
            ->where('auth.canManageUsers', true));
});
