<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Refuses organisation slugs that clash with the app's own paths or with system names.
 *
 * A slug is a public identifier: it travels in the `orgs` claim to every relying party, picks
 * the sign-in branding (`?organization=`, `login_hint`) and may become a path or subdomain on
 * the client side. Reserved (maintainer decision, 2026-11-23, #255):
 *
 * - the first path segment of every route, read from the route table, so a new route reserves
 *   its segment without anyone updating a list;
 * - the top-level names in `public/` (`build`, `storage`, `favicon`, …), served before Laravel;
 * - fixed names: system names and paths that exist only on some installs or later;
 * - every slug of one or two characters.
 *
 * The check runs on the normalised slug ({@see self::normalize()}), so `Admin`, `ADMIN`,
 * `admin_` and `àdmin` are all `admin`.
 */
final class ReservedOrganizationSlugRule implements ValidationRule
{
    /**
     * @param  string|null  $currentSlug  the organisation's slug on update: an unchanged slug that
     *                                    predates this rule stays valid, so its other fields remain editable
     */
    public function __construct(private readonly ?string $currentSlug = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ($this->currentSlug !== null && $value === $this->currentSlug)) {
            return;
        }

        if (self::isReserved($value)) {
            $fail(__('This slug is reserved. Choose another one.'));
        }
    }

    public static function isReserved(string $slug): bool
    {
        $normalizedSlug = self::normalize($slug);

        // One and two characters: initials, country codes and short words are all reserved.
        return Str::length($normalizedSlug) <= 2 || self::reservedSlugs()->containsStrict($normalizedSlug);
    }

    /**
     * Lower case, transliterated to ASCII, every run of separators (`_`, `.`, spaces) one dash,
     * no leading or trailing dash: `.well-known` → `well-known`, `Admin_` → `admin`.
     */
    public static function normalize(string $slug): string
    {
        return Str::slug($slug);
    }

    /**
     * Route segments (a parameter segment such as `{path}` reserves its bare name), public file
     * names without their extension, and the fixed names, all normalised.
     *
     * @return Collection<int, string>
     */
    private static function reservedSlugs(): Collection
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn (RoutingRoute $route): string => Str::before($route->uri(), '/'))
            ->merge(collect(File::glob(public_path('*')))->map(fn (string $path): string => pathinfo($path, PATHINFO_FILENAME)))
            ->merge(self::fixedReservedSlugs())
            ->map(fn (string $name): string => self::normalize($name));
    }

    /**
     * Names no route segment or public file yields, reserved all the same.
     *
     * @return list<string>
     */
    private static function fixedReservedSlugs(): array
    {
        return [
            // System and support names (decision of 2026-11-23).
            'www', 'mail', 'root', 'administrator', 'support', 'help', 'status', 'security',
            // Doors the app answers or plans to answer outside the route table on some installs.
            'mcp', 'saml', 'scim', 'ldap', 'oidc', 'auth', 'horizon', 'telescope', 'pulse', 'reverb',
            // Common client-side names for an identity provider and its apps.
            'app', 'sso', 'signaculum', 'qiick',
        ];
    }
}
