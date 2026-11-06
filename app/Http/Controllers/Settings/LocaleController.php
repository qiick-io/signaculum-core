<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Persists the signed-in user's UI locale (and mirrors it in a cookie for the login
 * pages, where nobody is signed in).
 */
final class LocaleController extends Controller
{
    public function update(UpdateLocaleRequest $request): RedirectResponse
    {
        $locale = $request->string('locale')->toString();

        $request->user()?->forceFill(['locale' => $locale])->save();

        return back()->cookie('locale', $locale, 60 * 24 * 365);
    }
}
