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
