<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    app(SettingsRepositoryService::class)->save([
        'login_lockout_threshold' => 3,
        'login_lockout_minutes' => 30,
        'login_max_attempts' => 100,
    ]);
});

test('failed logins lock the account after the configured threshold', function (): void {
    $user = User::factory()->create([
        'email' => 'locked@example.test',
        'password' => Hash::make('correct-horse'),
    ]);

    foreach (range(1, 2) as $attempt) {
        $this->from('/login')
            ->post('/login', [
                'email' => 'locked@example.test',
                'password' => 'wrong-password',
            ])
            ->assertRedirect('/login');

        expect($user->fresh()->failed_login_attempts)->toBe($attempt);
        expect($user->fresh()->locked_until)->toBeNull();
    }

    $this->from('/login')
        ->post('/login', [
            'email' => 'locked@example.test',
            'password' => 'wrong-password',
        ])
        ->assertRedirect('/login');

    $user->refresh();

    expect($user->failed_login_attempts)->toBe(0);
    expect($user->locked_until)->not->toBeNull();
    expect($user->locked_until->isFuture())->toBeTrue();

    $this->from('/login')
        ->post('/login', [
            'email' => 'locked@example.test',
            'password' => 'correct-horse',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

test('a successful login clears the failure counter', function (): void {
    $user = User::factory()->create([
        'email' => 'ok@example.test',
        'password' => Hash::make('correct-horse'),
        'failed_login_attempts' => 2,
    ]);

    $this->post('/login', [
        'email' => 'ok@example.test',
        'password' => 'correct-horse',
    ])->assertRedirect();

    expect(auth()->check())->toBeTrue();
    expect($user->fresh()->failed_login_attempts)->toBe(0);
    expect($user->fresh()->locked_until)->toBeNull();
});

test('an instance admin can clear a lockout', function (): void {
    $admin = User::factory()->instanceAdmin()->create();
    $user = User::factory()->create([
        'locked_until' => Carbon::now()->addHour(),
        'failed_login_attempts' => 0,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.users.lockout.destroy', $user))
        ->assertRedirect(route('admin.users.index'));

    expect($user->fresh()->locked_until)->toBeNull();
});
