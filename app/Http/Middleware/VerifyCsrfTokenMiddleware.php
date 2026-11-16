<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

/**
 * CSRF verification with a narrow exemption for OIDC authorization POST.
 *
 * RPs may POST form-serialized authorization requests to `/oauth/authorize`
 * (OIDC Core 1.0 §3.1.2.1) without a Signaculum session cookie or CSRF token.
 * Consent approval on the same path carries `auth_token` and must stay protected.
 * End-session remains listed in {@see bootstrap/app.php} `validateCsrfTokens(except:)`.
 */
final class VerifyCsrfTokenMiddleware extends ValidateCsrfToken
{
    /**
     * @param  Request  $request
     */
    protected function inExceptArray($request): bool
    {
        if ($this->isOidcAuthorizationRequestPost($request)) {
            return true;
        }

        return parent::inExceptArray($request);
    }

    /**
     * Cross-site RP authorization POST: has `response_type`, no consent `auth_token`.
     *
     * Public for unit tests: feature tests skip CSRF via `runningUnitTests()`.
     */
    public function isOidcAuthorizationRequestPost(Request $request): bool
    {
        if (! $request->isMethod('POST') || ! $request->is('oauth/authorize')) {
            return false;
        }

        $authToken = $request->input('auth_token');

        if (is_string($authToken) && $authToken !== '') {
            return false;
        }

        $responseType = $request->input('response_type');

        return is_string($responseType) && $responseType !== '';
    }
}
