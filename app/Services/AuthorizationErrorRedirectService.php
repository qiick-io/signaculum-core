<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirects authorization errors to a registered client redirect URI.
 *
 * OIDC Core §3.1.2.6: when the End-User cannot be authenticated silently (or
 * another authorization error applies), the Authorization Server returns the
 * error to the client's `redirect_uri` with `state`. RFC 9207 adds `iss`.
 * Unknown, revoked, or unregistered `redirect_uri` → HTTP 401 (no open redirect).
 */
final class AuthorizationErrorRedirectService
{
    /**
     * @param  non-empty-string  $error  OIDC error code (e.g. `login_required`, `interaction_required`)
     */
    public function redirect(Request $request, string $error): RedirectResponse|Response
    {
        $clientId = $request->input('client_id');
        $redirectUri = $request->input('redirect_uri');

        if (! is_string($clientId) || $clientId === '' || ! is_string($redirectUri) || $redirectUri === '') {
            abort(401);
        }

        $client = Client::query()->find($clientId);

        if ($client === null || $client->revoked || ! in_array($redirectUri, $client->redirect_uris, true)) {
            abort(401);
        }

        $query = [
            'error' => $error,
            'iss' => (string) config('oidc.issuer'),
        ];

        $state = $request->input('state');

        if (is_string($state) && $state !== '') {
            $query['state'] = $state;
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return redirect()->away($redirectUri.$separator.http_build_query($query));
    }
}
