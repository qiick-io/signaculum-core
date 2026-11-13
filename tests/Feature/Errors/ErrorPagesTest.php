<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->withoutVite();
});

test('guest 404 on web routes redirects to login', function (): void {
    $this->get('/this-route-does-not-exist')
        ->assertRedirect(route('login'));
});

test('authenticated 404 renders the inertia error page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error-page')
            ->where('status', 404)
            ->where('homeUrl', route('dashboard')));
});

test('authenticated 403 renders the inertia error page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error-page')
            ->where('status', 403)
            ->where('homeUrl', route('dashboard')));
});

test('authenticated 503 renders the inertia error page', function (): void {
    $user = User::factory()->create();

    Route::middleware('web')->get('/__test-service-unavailable', function () {
        throw new HttpException(503, 'Unavailable');
    });

    $this->actingAs($user)
        ->get('/__test-service-unavailable')
        ->assertStatus(503)
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error-page')
            ->where('status', 503));
});

test('authenticated 500 renders the inertia error page when debug is off', function (): void {
    config(['app.debug' => false]);

    $user = User::factory()->create();

    Route::middleware('web')->get('/__test-server-error', function () {
        throw new RuntimeException('boom');
    });

    $this->actingAs($user)
        ->get('/__test-server-error')
        ->assertStatus(500)
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error-page')
            ->where('status', 500));
});

test('oidc discovery 404 keeps the json response', function (): void {
    $this->getJson('/.well-known/this-does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('model-not-found 404 shares auth so the app layout can render', function (): void {
    $admin = User::factory()->instanceAdmin()->create();

    // Route matches (session + auth run) but binding fails before HandleInertiaRequests.
    Route::middleware('web')->get('/__test-users/{user}', function (User $user) {
        return response('ok');
    });

    $this->actingAs($admin)
        ->get('/__test-users/999999')
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors/error-page')
            ->where('auth.user.id', $admin->id)
            ->where('auth.canManageUsers', true));
});
