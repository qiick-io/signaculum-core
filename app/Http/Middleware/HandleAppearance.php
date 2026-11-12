<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

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
        View::share('appearance', $request->cookie('appearance') ?? 'system');
        View::share('accessibilityHighContrast', $request->cookie('accessibility_high_contrast') === '1');
        View::share('accessibilityReduceMotion', $request->cookie('accessibility_reduce_motion') === '1');

        return $next($request);
    }
}
