<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set the request locale (UI, validation and mail) from the signed-in user, then the
 * `locale` cookie, then `config('app.locale')`. Copied from Excubia without the project
 * default, which Signaculum does not have.
 */
final class SetLocaleMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $availableLocales = array_keys((array) config('i18n.available_locales'));
        $authenticatedUser = $request->user();
        // Read from the attributes array: a freshly factory-made User may not have
        // `locale` loaded yet, and Model::shouldBeStrict() would throw on `->locale`.
        $userLocale = is_object($authenticatedUser)
            ? ($authenticatedUser->getAttributes()['locale'] ?? null)
            : null;

        foreach ([$userLocale, $request->cookie('locale')] as $candidateLocale) {
            if (is_string($candidateLocale) && in_array($candidateLocale, $availableLocales, true)) {
                return $candidateLocale;
            }
        }

        return (string) config('app.locale');
    }
}
