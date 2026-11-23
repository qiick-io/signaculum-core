<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST `/oauth/authorize`: OIDC authorization requests and Passport consent approval.
 *
 * OIDC Core 1.0 §3.1.2.1 requires the authorization endpoint to accept form-serialized
 * POST as well as GET. Passport uses POST on the same path for consent approval
 * (`auth_token`). This controller dispatches:
 *
 * - Authorization request (`response_type` present, no `auth_token`): promote the
 *   form body into the query string (league/oauth2-server reads query params only)
 *   and run {@see AuthorizationController::authorize()}.
 * - Consent approval (`auth_token` present): require a signed-in user and run
 *   {@see ApproveAuthorizationController::approve()}.
 *
 * Replaces Passport's `passport.authorizations.approve` action so guests can start
 * an authorization request over POST (redirect to login), matching GET. Laravel only
 * remembers the intended URL of a GET request, so the login redirect stores the GET
 * form of the promoted request itself: after login the user resumes the authorization.
 */
final class AuthorizationEndpointPostController extends Controller
{
    public function __construct(
        private readonly AuthorizationController $authorizationController,
        private readonly ApproveAuthorizationController $approveAuthorizationController,
    ) {}

    /**
     * @throws AuthenticationException when consent is posted without a signed-in user
     */
    public function __invoke(
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse,
    ): Response|AuthorizationViewResponse {
        if ($this->isConsentApproval($request)) {
            if (Auth::guard('web')->guest()) {
                throw new AuthenticationException(guards: ['web']);
            }

            return $this->approveAuthorizationController->approve($request, $psrResponse);
        }

        if (! $this->isAuthorizationRequest($request)) {
            abort(400);
        }

        $this->promoteFormBodyToQuery($request);

        $psrRequest = (new PsrHttpFactory)->createRequest($request);

        try {
            return $this->authorizationController->authorize(
                $psrRequest,
                $request,
                $psrResponse,
                $viewResponse,
            );
        } catch (AuthenticationException) {
            redirect()->setIntendedUrl($request->fullUrl());

            return redirect()->route('login');
        }
    }

    /**
     * Consent approval from the Signaculum UI (Passport `auth_token`), not an RP POST.
     */
    private function isConsentApproval(Request $request): bool
    {
        $authToken = $request->input('auth_token');

        return is_string($authToken) && $authToken !== '';
    }

    /**
     * RP authorization request (OIDC Core §3.1.2.1 form serialization).
     */
    private function isAuthorizationRequest(Request $request): bool
    {
        $responseType = $request->input('response_type');

        return is_string($responseType) && $responseType !== '';
    }

    /**
     * league/oauth2-server's auth code grant reads only query parameters
     * ({@see AuthCodeGrant::canRespondToAuthorizationRequest}).
     * Copy the form body into the query bag and QUERY_STRING so PSR conversion and
     * `Request::fullUrl()` (login intended URL) see the same parameters as GET.
     */
    private function promoteFormBodyToQuery(Request $request): void
    {
        foreach ($request->request->all() as $name => $value) {
            if ($request->query->has($name)) {
                continue;
            }

            $request->query->set($name, $value);
        }

        $request->server->set(
            'QUERY_STRING',
            http_build_query($request->query->all(), '', '&', PHP_QUERY_RFC3986),
        );
    }
}
