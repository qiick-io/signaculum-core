<?php

declare(strict_types=1);

use App\Enums\ThemePresetEnum;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use App\Services\ThemePresetService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Instance settings pages copied from Excubia's project settings (General, Localization,
 * Authentication & security, Appearance), all for instance admins only.
 */
function instanceAdmin(): User
{
    return User::factory()->create(['is_instance_admin' => true]);
}

function generalSettingsPayload(array $overrides = []): array
{
    return [
        'app_name' => 'Acme ID',
        'description' => 'Sign-in for the Acme apps.',
        'report_bug_url' => 'https://example.com/bugs/new',
        'activity_retention_days' => 365,
        ...$overrides,
    ];
}

function securitySettingsPayload(array $overrides = []): array
{
    return [
        'password_policy' => 'medium',
        'login_max_attempts' => 3,
        'registration_enabled' => '0',
        'two_factor_required' => '0',
        ...$overrides,
    ];
}

dataset('instance settings pages', [
    'general' => ['instance.edit', 'settings/instance'],
    'localization' => ['instance.localization.edit', 'settings/instance/localization'],
    'authentication & security' => ['instance.security.edit', 'settings/instance/security'],
    'appearance' => ['appearance.edit', 'settings/appearance'],
]);

dataset('instance settings writes', [
    'general' => ['put', 'instance.update'],
    'localization' => ['put', 'instance.localization.update'],
    'authentication & security' => ['put', 'instance.security.update'],
    'appearance' => ['put', 'appearance.update'],
]);

it('shows every instance settings page to instance admins', function (string $route, string $component) {
    $this->actingAs(instanceAdmin())->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with('instance settings pages');

it('keeps every instance settings page from users who are not instance admins', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertForbidden();
})->with('instance settings pages');

it('keeps every instance settings write from users who are not instance admins', function (string $method, string $route) {
    $this->actingAs(User::factory()->create())
        ->{$method}(route($route), [...generalSettingsPayload(), ...securitySettingsPayload(), 'default_language' => 'de', 'default_appearance' => 'dark'])
        ->assertForbidden();

    expect(Setting::query()->count())->toBe(0);
})->with('instance settings writes');

it('sends guests to the login page', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('instance settings pages');

it('shows the defaults until a value is saved', function () {
    $admin = instanceAdmin();

    $this->actingAs($admin)->get(route('instance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('instance.app_name', null)
            ->where('instance.description', null)
            ->where('instance.report_bug_url', null));

    $this->actingAs($admin)->get(route('instance.security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('instance.login_max_attempts', 5)
            ->where('instance.registration_enabled', false)
            ->where('instance.two_factor_required', false)
            ->where('passwordPolicies', SettingsRepositoryService::PASSWORD_POLICIES));
});

it('saves the general settings and the app uses them', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.update'), generalSettingsPayload())
        ->assertRedirect(route('instance.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $settings = app(SettingsRepositoryService::class);

    expect($settings->appName())->toBe('Acme ID')
        ->and($settings->reportBugUrl())->toBe('https://example.com/bugs/new')
        ->and($settings->all()['description'])->toBe('Sign-in for the Acme apps.');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('name', 'Acme ID')
        ->where('instanceSettings.reportBugUrl', 'https://example.com/bugs/new'));
});

it('shares no report bug URL until one is saved, so the settings nav uses the issue form', function () {
    $this->actingAs(instanceAdmin())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('instanceSettings.reportBugUrl', null));

    expect(base_path('.github/ISSUE_TEMPLATE/bug_report.yml'))->toBeFile();
});

it('validates the general settings', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.update'), generalSettingsPayload([
            'app_name' => str_repeat('a', 101),
            'description' => str_repeat('a', 1001),
            'report_bug_url' => 'not a url',
        ]))
        ->assertSessionHasErrors(['app_name', 'description', 'report_bug_url']);
});

it('saves the authentication and security settings and the app uses them', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.security.update'), securitySettingsPayload())
        ->assertRedirect(route('instance.security.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    $settings = app(SettingsRepositoryService::class);

    expect($settings->passwordPolicy())->toBe('medium')
        ->and($settings->loginMaxAttempts())->toBe(3)
        ->and($settings->isRegistrationEnabled())->toBeFalse()
        ->and($settings->isTwoFactorRequired())->toBeFalse();

    expect(validator(['password' => 'alllowercase'], ['password' => Password::default()])->fails())->toBeTrue()
        ->and(validator(['password' => 'Mixed1Case'], ['password' => Password::default()])->passes())->toBeTrue();
});

it('turns self-registration on from authentication and security settings', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.security.update'), securitySettingsPayload([
            'registration_enabled' => '1',
        ]))
        ->assertRedirect(route('instance.security.edit'));

    expect(app(SettingsRepositoryService::class)->isRegistrationEnabled())->toBeTrue();

    $this->post(route('logout'));

    $this->get(route('register'))->assertOk();
});

it('validates the authentication and security settings', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.security.update'), securitySettingsPayload([
            'password_policy' => 'none',
            'login_max_attempts' => 0,
            'registration_enabled' => 'maybe',
            'two_factor_required' => 'maybe',
        ]))
        ->assertSessionHasErrors(['password_policy', 'login_max_attempts', 'registration_enabled', 'two_factor_required']);
});

it('uses the saved login attempts for the login rate limit', function () {
    app(SettingsRepositoryService::class)->save(['login_max_attempts' => 2]);
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 2);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();
});

it('sends users without 2FA to their security page when 2FA is required', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.security.update'), securitySettingsPayload(['two_factor_required' => '1']))
        ->assertRedirect(route('instance.security.edit'));

    expect(app(SettingsRepositoryService::class)->isTwoFactorRequired())->toBeTrue();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('security.edit'));

    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

it('lets an OAuth login continue after enrolling when 2FA is required', function () {
    app(SettingsRepositoryService::class)->save(['two_factor_required' => true]);
    $client = createOidcClient();
    $authorizeUrl = authorizeUrl($client, pkcePair()['challenge']);

    $this->actingAs(User::factory()->create())
        ->get($authorizeUrl)
        ->assertRedirect(route('security.edit'));

    parse_str((string) parse_url((string) session('url.intended'), PHP_URL_QUERY), $intendedQuery);
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $authorizeQuery);

    expect(session('url.intended'))->toStartWith(url('/oauth/authorize'))
        ->and($intendedQuery)->toEqual($authorizeQuery);
});

it('saves the default language and uses it after the user and cookie choice', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.localization.update'), ['default_language' => 'de'])
        ->assertRedirect(route('instance.localization.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    auth()->logout();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
    $this->withUnencryptedCookie('locale', 'it')->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'it'));

    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('validates the default language', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('instance.localization.update'), ['default_language' => 'fr'])
        ->assertSessionHasErrors('default_language');
});

it('saves the default appearance and uses it when there is no appearance cookie', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('appearance.update'), ['default_appearance' => 'dark'])
        ->assertRedirect(route('appearance.edit'))
        ->assertInertiaFlash('toast.type', 'success');

    expect(app(SettingsRepositoryService::class)->defaultAppearance())->toBe('dark');

    auth()->logout();

    $this->get(route('login'))->assertSee("const appearance = 'dark';", false);
    $this->withUnencryptedCookie('appearance', 'light')->get(route('login'))
        ->assertSee("const appearance = 'light';", false);
});

it('validates the default appearance', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('appearance.update'), ['default_appearance' => 'sepia'])
        ->assertSessionHasErrors('default_appearance');
});

it('offers the built-in theme presets on the appearance page', function () {
    $this->actingAs(instanceAdmin())->get(route('appearance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('appearance.theme_preset', 'signaculum')
            ->where('appearance.project_color', null)
            ->where('appearance.brand_passes_aa', true)
            ->has('appearance.theme_presets', count(ThemePresetEnum::cases()))
            ->where('appearance.theme_presets.0.slug', 'signaculum')
            ->where('appearance.theme_presets.0.light.primary', '#A24A7B')
            ->where('appearance.theme_presets.0.dark.primary', '#DC77AD')
            ->where('appearance.theme_presets.0.light.background', '#FFFFFF'));
});

it('saves the theme preset and brand colours', function () {
    $manager = instanceAdmin();

    $this->actingAs($manager)
        ->put(route('appearance.update'), [
            'theme_preset' => 'indigo',
            'project_color' => '#123456',
            'project_color_dark' => '',
            'default_appearance' => 'system',
        ])
        ->assertRedirect(route('appearance.edit'))
        ->assertSessionHasNoErrors();

    $settings = app(SettingsRepositoryService::class);

    expect($settings->themePreset())->toBe('indigo')
        ->and($settings->projectColor())->toBe('#123456')
        ->and($settings->projectColorDark())->toBeNull();

    $this->actingAs($manager)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('projectAppearance.themePreset', 'indigo')
            ->where('projectAppearance.themeCss.brandPrimary', '#123456')
            ->where('projectAppearance.themeCss.brandPrimaryDark', '#123456'));
});

it('keeps the stored theme preset when the request omits it', function () {
    app(SettingsRepositoryService::class)->save(['theme_preset' => 'rose']);

    $this->actingAs(instanceAdmin())
        ->put(route('appearance.update'), ['default_appearance' => 'light'])
        ->assertSessionHasNoErrors();

    expect(app(SettingsRepositoryService::class)->themePreset())->toBe('rose');
});

it('rejects an unknown theme preset and invalid brand colours', function () {
    $this->actingAs(instanceAdmin())
        ->put(route('appearance.update'), [
            'theme_preset' => 'neon',
            'project_color' => 'red',
            'project_color_dark' => '#12345',
            'default_appearance' => 'system',
        ])
        ->assertSessionHasErrors(['theme_preset', 'project_color', 'project_color_dark']);

    expect(app(SettingsRepositoryService::class)->themePreset())->toBe('signaculum');
});

it('merges the shared base with the preset brand colours', function () {
    $signaculum = app(ThemePresetService::class)->resolve(null, null, null);
    $violet = app(ThemePresetService::class)->resolve('violet', null, null);

    expect($signaculum['themePreset'])->toBe('signaculum')
        ->and($signaculum['light']['primary'])->toBe('#A24A7B')
        ->and($signaculum['light']['sidebar'])->toBe(config('theme-presets.base.light.sidebar'))
        ->and($signaculum['dark']['background'])->toBe('#0E171E')
        ->and($signaculum['light']['ai'])->toBe('#7F35BB')
        ->and($violet['light']['ai'])->toBe(config('theme-presets.ai_override_light.ai'))
        ->and($violet['dark']['ai'])->toBe(config('theme-presets.ai_override_dark.ai'));
});

it('renders the theme variables of a non-default preset in the HTML', function () {
    app(SettingsRepositoryService::class)->save(['theme_preset' => 'indigo']);
    $indigo = config('theme-presets.presets.indigo');

    $this->get(route('login'))
        ->assertSee('<style id="instance-theme-css">', false)
        ->assertSee('--primary: '.$indigo['light']['primary'].';', false)
        ->assertSee('--primary: '.$indigo['dark']['primary'].';', false)
        ->assertDontSee('--brand-primary:', false);
});

it('renders the custom brand colour with an accessible foreground', function () {
    app(SettingsRepositoryService::class)->save(['theme_preset' => 'indigo', 'project_color' => '#FFEE00']);

    $this->get(route('login'))
        ->assertSee('--brand-primary: #FFEE00;', false)
        ->assertSee('--brand-primary-foreground: #0E171E;', false);
});

it('keeps a contrast-safe focus ring for a low-contrast brand colour', function () {
    // #0F172A is about 17:1 on white but about 1.1:1 on the dark background (externa-core#453).
    $themeCss = app(ThemePresetService::class)->resolve('teal', '#0F172A', null);

    expect($themeCss['light']['ring'])->toBe('#0F172A')
        ->and($themeCss['light']['sidebar-ring'])->toBe('#0F172A')
        ->and($themeCss['dark']['ring'])->toBe('var(--color-teal-400)')
        ->and($themeCss['dark']['sidebar-ring'])->toBe('var(--color-teal-400)');

    $faint = app(ThemePresetService::class)->resolve('teal', '#F5F5F5', '#38BDF8');

    expect($faint['light']['ring'])->toBe('var(--color-teal-600)')
        ->and($faint['dark']['ring'])->toBe('#38BDF8');
});
