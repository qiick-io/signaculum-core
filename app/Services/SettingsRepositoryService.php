<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ThemePresetEnum;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * Instance settings stored in the `settings` table, merged over defaults, with typed getters
 * for the code that uses them (login throttle, password rule, self-registration, required 2FA,
 * UI language, appearance and branding).
 *
 * Copied from qiick-io/excubia-app and trimmed to what Signaculum has. Setting keys keep the
 * externa-core / Excubia names (`project_color`, `project_logo`, …) so the three apps read the
 * same way; in Signaculum "project" means this instance.
 *
 * Registered as a singleton; values are read once per instance and reloaded after a write.
 */
final class SettingsRepositoryService
{
    public const array PASSWORD_POLICIES = ['weak', 'medium', 'strong'];

    public const array APPEARANCES = ['system', 'light', 'dark'];

    /**
     * Uploaded branding files: the public asset name → the setting holding its path on the
     * local disk (`branding/`).
     */
    public const array BRANDING_ASSETS = [
        'logo' => 'project_logo',
        'logo-dark' => 'project_logo_dark',
        'favicon' => 'public_favicon',
    ];

    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * Defaults for every key.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'app_name' => null,
            'description' => null,
            'report_bug_url' => null,
            'default_language' => $this->configLanguage(),
            'default_appearance' => 'system',
            'theme_preset' => ThemePresetEnum::default()->value,
            'project_color' => null,
            'project_color_dark' => null,
            'project_logo' => null,
            'project_logo_dark' => null,
            'public_favicon' => null,
            'password_policy' => app()->isProduction() ? 'strong' : 'weak',
            'login_max_attempts' => 5,
            // Closed IdP default: self-hosting stays invite-only until an admin turns this on
            // (qiick Cloud can enable it). Decision of 2026-11-13 on #23.
            'registration_enabled' => false,
            'two_factor_required' => false,
            'activity_retention_days' => (int) config('activitylog.clean_after_days', 365),
        ];
    }

    /**
     * Every setting, stored values over defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values ??= array_merge(
            $this->defaults(),
            Setting::query()->pluck('value', 'key')->all(),
        );
    }

    /**
     * Only the given keys, for one settings page.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function forEditOnly(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    /**
     * Saves the given keys in one transaction.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        });

        $this->values = null;
    }

    /**
     * The instance name shown in the UI and the browser title; `APP_NAME` until one is saved.
     */
    public function appName(): string
    {
        return $this->string('app_name') ?? (string) config('app.name');
    }

    /**
     * Public URLs of the uploaded logo, dark logo and favicon (null when not uploaded), served
     * by BrandingController. The stored file name changes on every upload, so it busts caches.
     *
     * @return array{logoUrl: string|null, logoDarkUrl: string|null, faviconUrl: string|null}
     */
    public function brandingUrls(): array
    {
        $url = function (string $asset): ?string {
            $path = $this->string(self::BRANDING_ASSETS[$asset]);

            return $path === null ? null : route('branding.show', ['asset' => $asset, 'v' => pathinfo($path, PATHINFO_FILENAME)]);
        };

        return ['logoUrl' => $url('logo'), 'logoDarkUrl' => $url('logo-dark'), 'faviconUrl' => $url('favicon')];
    }

    public function reportBugUrl(): ?string
    {
        return $this->string('report_bug_url');
    }

    /**
     * Default UI locale for people without a personal or cookie choice.
     */
    public function defaultLanguage(): string
    {
        $language = $this->all()['default_language'];

        return is_string($language) && in_array($language, $this->availableLanguages(), true)
            ? $language
            : $this->configLanguage();
    }

    /**
     * Light/dark/system default for visitors without an `appearance` cookie.
     */
    public function defaultAppearance(): string
    {
        $appearance = $this->all()['default_appearance'];

        return is_string($appearance) && in_array($appearance, self::APPEARANCES, true) ? $appearance : 'system';
    }

    public function themePreset(): string
    {
        $preset = $this->all()['theme_preset'];

        return ThemePresetEnum::tryFromNullable(is_string($preset) ? $preset : null)->value;
    }

    /**
     * Custom brand colour (`#RRGGBB`) that overrides the preset primary, or null.
     */
    public function projectColor(): ?string
    {
        return $this->hexColor('project_color');
    }

    /**
     * Custom dark-mode brand colour, or null (the light one is used then).
     */
    public function projectColorDark(): ?string
    {
        return $this->hexColor('project_color_dark');
    }

    public function passwordPolicy(): string
    {
        $policy = $this->all()['password_policy'];

        return in_array($policy, self::PASSWORD_POLICIES, true) ? $policy : 'strong';
    }

    /**
     * The password rule of the saved policy, used as `Password::defaults()`.
     */
    public function passwordRule(): Password
    {
        return match ($this->passwordPolicy()) {
            'weak' => Password::min(6),
            'medium' => Password::min(8)->mixedCase()->numbers(),
            default => Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised(),
        };
    }

    /**
     * Login attempts per minute for one email and IP (Fortify `login` rate limiter).
     */
    public function loginMaxAttempts(): int
    {
        return $this->integer('login_max_attempts', 1, 100);
    }

    /**
     * Whether guests may open `/register` and create an account. Off by default so a fresh
     * Signaculum install is a closed IdP; instance admins turn it on in Settings → Authentication
     * & security (for example on qiick Cloud).
     */
    public function isRegistrationEnabled(): bool
    {
        return (bool) $this->all()['registration_enabled'];
    }

    public function isTwoFactorRequired(): bool
    {
        return (bool) $this->all()['two_factor_required'];
    }

    /**
     * Days to keep activity log rows before the daily `activitylog:clean` schedule deletes them.
     */
    public function activityRetentionDays(): int
    {
        return $this->integer('activity_retention_days', 1, 3650);
    }

    /**
     * @return list<string>
     */
    private function availableLanguages(): array
    {
        return array_keys((array) config('i18n.available_locales', ['en' => 'English']));
    }

    private function configLanguage(): string
    {
        $locale = (string) config('app.locale', 'en');

        return in_array($locale, $this->availableLanguages(), true) ? $locale : 'en';
    }

    private function hexColor(string $key): ?string
    {
        $value = $this->string($key);

        return $value !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : null;
    }

    private function string(string $key): ?string
    {
        $value = $this->all()[$key];

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function integer(string $key, int $min, int $max): int
    {
        $value = $this->all()[$key];

        return max($min, min($max, is_numeric($value) ? (int) $value : (int) $this->defaults()[$key]));
    }
}
