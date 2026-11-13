<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\SettingsRepositoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_is_disabled_by_default(): void
    {
        expect(app(SettingsRepositoryService::class)->isRegistrationEnabled())->toBeFalse();

        $this->get(route('register'))->assertForbidden();

        $this->post(route('register.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
    }

    public function test_login_page_hides_sign_up_when_registration_is_disabled(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/login')
                ->where('canRegister', false));
    }

    public function test_registration_screen_can_be_rendered_when_enabled(): void
    {
        app(SettingsRepositoryService::class)->save(['registration_enabled' => true]);

        $this->get(route('register'))->assertOk();
    }

    public function test_new_users_can_register_when_enabled(): void
    {
        app(SettingsRepositoryService::class)->save(['registration_enabled' => true]);

        $response = $this->post(route('register.store'), [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_login_page_shows_sign_up_when_registration_is_enabled(): void
    {
        app(SettingsRepositoryService::class)->save(['registration_enabled' => true]);

        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/login')
                ->where('canRegister', true));
    }
}
