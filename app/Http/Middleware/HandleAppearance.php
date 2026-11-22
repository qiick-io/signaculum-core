<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\OrganizationBrandingService;
use App\Services\SettingsRepositoryService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shares appearance and accessibility cookies with the root Blade view so the
 * first paint already has the correct theme and contrast classes on `<html>`.
 */
class HandleAppearance
{
    /**
     * Share the appearance and accessibility cookies with app.blade.php, which stamps the
     * matching classes on `<html>` before the first paint (no flash of the wrong theme).
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $settings = app(SettingsRepositoryService::class);
        $appearanceCookie = $request->cookie('appearance');

        // The personal cookie wins; the instance default applies until someone chooses.
        View::share('appearance', in_array($appearanceCookie, SettingsRepositoryService::APPEARANCES, true) ? $appearanceCookie : $settings->defaultAppearance());
        View::share('accessibilityHighContrast', $request->cookie('accessibility_high_contrast') === '1');
        View::share('accessibilityReduceMotion', $request->cookie('accessibility_reduce_motion') === '1');
        // Organisation brand colours on sign-in and consent; the instance theme everywhere else.
        View::share('projectThemeCss', app(OrganizationBrandingService::class)->effectiveAppearance($request)['themeCss']);
        View::share('projectFaviconUrl', $settings->brandingUrls()['faviconUrl']);

        return $next($request);
    }
}
