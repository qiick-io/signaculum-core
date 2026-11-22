<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsRepositoryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the request locale and answer with `Content-Language` (RFC 9110 §8.4).
 *
 * Web: the signed-in user's saved locale, then the `locale` cookie, then the instance default
 * (Settings → Localization). This order is unchanged from Excubia.
 *
 * REST API and MCP: `Accept-Language` negotiated with Symfony's native
 * `Request::getPreferredLanguage()` (q-values), then the authenticated principal's saved locale,
 * then the instance default. An unsupported or absent `Accept-Language` falls through; it never
 * produces a 406. The `error` key of an API response is a code, never a translated string.
 *
 * Global API middleware runs before key authentication, so a Bearer key owner is not known here.
 * `AuthenticateApiKeyMiddleware` calls `applyPrincipalLocale()` after it authenticates the owner;
 * this middleware reads the final locale after `$next()` and writes the response header.
 */
final class SetLocaleMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        $response = $next($request);

        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }

    /**
     * Apply a machine principal's saved locale when no supported language was requested.
     *
     * Called after API key authentication, which runs inside the global locale middleware. A
     * requested language always wins; an unsupported principal locale is ignored.
     */
    public static function applyPrincipalLocale(Request $request, ?string $principalLocale): void
    {
        if (! self::isMachineDoor($request)) {
            return;
        }

        $availableLocales = self::availableLocales();

        if (self::preferredLanguage($request, $availableLocales) !== null) {
            return;
        }

        if (is_string($principalLocale) && in_array($principalLocale, $availableLocales, true)) {
            App::setLocale($principalLocale);
        }
    }

    private function resolve(Request $request): string
    {
        $availableLocales = self::availableLocales();

        if (self::isMachineDoor($request)) {
            return self::preferredLanguage($request, $availableLocales)
                ?? self::principalLocale($request, $availableLocales)
                ?? self::instanceLocale($availableLocales);
        }

        $authenticatedUser = $request->user();
        $userLocale = is_object($authenticatedUser) ? $authenticatedUser->locale : null;

        foreach ([$userLocale, $request->cookie('locale')] as $candidateLocale) {
            if (is_string($candidateLocale) && in_array($candidateLocale, $availableLocales, true)) {
                return $candidateLocale;
            }
        }

        return app(SettingsRepositoryService::class)->defaultLanguage();
    }

    private static function isMachineDoor(Request $request): bool
    {
        return $request->is('api/*', 'mcp', 'mcp/*');
    }

    /**
     * @return list<string>
     */
    private static function availableLocales(): array
    {
        /** @var list<string> $availableLocales */
        $availableLocales = array_map('strval', array_keys((array) config('i18n.available_locales')));

        return $availableLocales;
    }

    /**
     * `getPreferredLanguage()` returns the first available locale when the header is absent or
     * names nothing we support, so check the requested languages first to preserve fall-through.
     *
     * @param  list<string>  $availableLocales
     */
    private static function preferredLanguage(Request $request, array $availableLocales): ?string
    {
        if (! $request->headers->has('Accept-Language')) {
            return null;
        }

        foreach ($request->getLanguages() as $requestedLanguage) {
            foreach ($availableLocales as $availableLocale) {
                if ($availableLocale === $requestedLanguage || str_starts_with($availableLocale, $requestedLanguage.'_')) {
                    return $request->getPreferredLanguage($availableLocales);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $availableLocales
     */
    private static function principalLocale(Request $request, array $availableLocales): ?string
    {
        $locale = data_get($request->user(), 'locale');

        return is_string($locale) && in_array($locale, $availableLocales, true) ? $locale : null;
    }

    /**
     * @param  list<string>  $availableLocales
     */
    private static function instanceLocale(array $availableLocales): string
    {
        $defaultLocale = app(SettingsRepositoryService::class)->defaultLanguage();

        if (in_array($defaultLocale, $availableLocales, true)) {
            return $defaultLocale;
        }

        $fallbackLocale = (string) config('app.fallback_locale', 'en');

        return in_array($fallbackLocale, $availableLocales, true) ? $fallbackLocale : 'en';
    }
}
