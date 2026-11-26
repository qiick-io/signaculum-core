<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Exceptions\SecondFactorUnavailableException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Qiick\Access\Contracts\TwoFactorResponder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signaculum's answers when the two-factor requirement stops a request ({@see TwoFactorResponder}):
 * the security page with a toast (keeping a GET, such as an OAuth authorization request, as the
 * intended URL so the login continues after enrolment), or a refusal with an explanation when no
 * second-factor method is enabled at all.
 */
final class AccessTwoFactorResponderService implements TwoFactorResponder
{
    public function enrol(Request $request): Response
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            redirect()->setIntendedUrl($request->fullUrl());
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => __('Set up two-factor authentication or a passkey to continue.')]);

        return redirect()->route('security.edit');
    }

    /**
     * @throws SecondFactorUnavailableException always: its renderer explains which setting to change
     */
    public function unavailable(): Response
    {
        throw new SecondFactorUnavailableException;
    }
}
