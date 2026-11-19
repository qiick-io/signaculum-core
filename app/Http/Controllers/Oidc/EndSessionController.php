<?php

declare(strict_types=1);

namespace App\Http\Controllers\Oidc;

use App\Actions\EndOidcSessionAction;
use App\Exceptions\InvalidIdTokenHintException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Oidc\EndSessionRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\IdTokenHintValidatorService;
use App\Services\OidcSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `end_session_endpoint` (OIDC RP-Initiated Logout 1.0).
 *
 * GET|POST `/oauth/end-session`. Ends only the user signed in to *this* browser.
 * An `id_token_hint` is not a credential: it skips confirmation when it matches
 * that user, and otherwise the End-User is asked (OIDC RP-Initiated Logout 1.0 §2).
 * `confirmed` on this CSRF-exempt endpoint is ignored; only POST
 * `oidc.end-session.confirm` confirms.
 *
 * After the local session ends, clients with a registered `frontchannel_logout_uri`
 * are notified via hidden iframes (OIDC Front-Channel Logout 1.0 §2) before the
 * post-logout redirect. Iframe URLs are stashed by the logout listener.
 */
final class EndSessionController extends Controller
{
    public function __invoke(
        EndSessionRequest $request,
        IdTokenHintValidatorService $idTokenHintValidatorService,
        EndOidcSessionAction $endOidcSessionAction,
    ): Response|RedirectResponse|SymfonyResponse {
        $idTokenHint = $request->string('id_token_hint')->toString();
        $clientId = $request->string('client_id')->toString();
        $postLogoutRedirectUri = $request->string('post_logout_redirect_uri')->toString();
        $state = $request->string('state')->toString();

        $clientId = $clientId !== '' ? $clientId : null;
        $postLogoutRedirectUri = $postLogoutRedirectUri !== '' ? $postLogoutRedirectUri : null;
        $state = $state !== '' ? $state : null;

        $hintSubject = null;
        $hintOidcSessionId = null;
        $hintAudiences = [];
        $isHintValid = false;

        if ($idTokenHint !== '') {
            try {
                $hint = $idTokenHintValidatorService->validate($idTokenHint, $clientId);
                $hintSubject = $hint['subject'];
                $hintOidcSessionId = $hint['oidcSessionId'];
                $hintAudiences = $hint['audience'];
                $isHintValid = true;

                if ($clientId === null && count($hintAudiences) === 1) {
                    $clientId = $hintAudiences[0];
                }
            } catch (InvalidIdTokenHintException) {
                $isHintValid = false;
            }
        }

        $client = $clientId !== null ? Client::query()->find($clientId) : null;

        if ($postLogoutRedirectUri !== null) {
            if ($client === null || ! $this->isRegisteredPostLogoutRedirectUri($client, $postLogoutRedirectUri)) {
                abort(400, 'The post_logout_redirect_uri is not registered for this client.');
            }
        }

        /** @var User|null $authenticatedUser */
        $authenticatedUser = Auth::guard('web')->user();

        // No browser session: nothing to end. A hint alone must never log anyone out
        // (hints travel in URLs/logs and expired ones are accepted).
        if ($authenticatedUser === null) {
            return $this->redirectAfterLogout($postLogoutRedirectUri, $state);
        }

        $hintUser = $hintSubject !== null
            ? $idTokenHintValidatorService->userForSubject($hintSubject)
            : null;

        $hintMatchesCurrentUser = $isHintValid
            && $hintUser !== null
            && $authenticatedUser->is($hintUser);

        // Missing, invalid, or foreign hint → ask the End-User (RP-Initiated Logout §2).
        // Never honour `confirmed` here: GET is CSRF-exempt.
        if (! $hintMatchesCurrentUser) {
            return Inertia::render('oauth/logout', [
                'clientId' => $clientId,
                'postLogoutRedirectUri' => $postLogoutRedirectUri,
                'state' => $state,
                'idTokenHint' => $idTokenHint !== '' ? $idTokenHint : null,
            ]);
        }

        return $this->endSessionAndRespond(
            request: $request,
            user: $authenticatedUser,
            oidcSessionId: $hintOidcSessionId,
            postLogoutRedirectUri: $postLogoutRedirectUri,
            state: $state,
            endOidcSessionAction: $endOidcSessionAction,
        );
    }

    /**
     * CSRF-protected confirmation: ends only the signed-in browser user.
     */
    public function confirm(
        Request $request,
        EndOidcSessionAction $endOidcSessionAction,
    ): Response|RedirectResponse|SymfonyResponse {
        $request->validate([
            'client_id' => ['sometimes', 'nullable', 'string'],
            'post_logout_redirect_uri' => ['sometimes', 'nullable', 'string', 'url'],
            'state' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $clientId = $request->string('client_id')->toString();
        $postLogoutRedirectUri = $request->string('post_logout_redirect_uri')->toString();
        $state = $request->string('state')->toString();

        $clientId = $clientId !== '' ? $clientId : null;
        $postLogoutRedirectUri = $postLogoutRedirectUri !== '' ? $postLogoutRedirectUri : null;
        $state = $state !== '' ? $state : null;

        $client = $clientId !== null ? Client::query()->find($clientId) : null;

        if ($postLogoutRedirectUri !== null) {
            if ($client === null || ! $this->isRegisteredPostLogoutRedirectUri($client, $postLogoutRedirectUri)) {
                abort(400, 'The post_logout_redirect_uri is not registered for this client.');
            }
        }

        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $this->redirectAfterLogout($postLogoutRedirectUri, $state);
        }

        return $this->endSessionAndRespond(
            request: $request,
            user: $user,
            oidcSessionId: null,
            postLogoutRedirectUri: $postLogoutRedirectUri,
            state: $state,
            endOidcSessionAction: $endOidcSessionAction,
        );
    }

    /**
     * End the session (back-channel via the logout listener), then either render
     * the front-channel iframe interstitial or continue to the post-logout URI.
     */
    private function endSessionAndRespond(
        Request $request,
        User $user,
        ?string $oidcSessionId,
        ?string $postLogoutRedirectUri,
        ?string $state,
        EndOidcSessionAction $endOidcSessionAction,
    ): Response|RedirectResponse|SymfonyResponse {
        $resolvedOidcSessionId = $oidcSessionId;

        if ($resolvedOidcSessionId === null && $request->hasSession()) {
            $sessionValue = $request->session()->get(OidcSessionService::SESSION_KEY);
            $resolvedOidcSessionId = is_string($sessionValue) && $sessionValue !== '' ? $sessionValue : null;
        }

        $endOidcSessionAction->execute(
            user: $user,
            oidcSessionId: $resolvedOidcSessionId,
            shouldLogoutWeb: true,
        );

        // Read from the container request: FormRequest is a copy, and the logout
        // listener stashes iframe URLs on the singleton (see EndOidcSessionOnLogoutListener).
        $activeRequest = request();
        $iframeUrls = $activeRequest->attributes->get('oidc.frontchannel_logout_urls');

        if (! is_array($iframeUrls) || $iframeUrls === []) {
            return $this->redirectAfterLogout($postLogoutRedirectUri, $state);
        }

        /** @var list<mixed> $iframeUrls */
        $urls = array_values(array_filter(
            $iframeUrls,
            static fn (mixed $url): bool => is_string($url) && $url !== '',
        ));

        if ($urls === []) {
            return $this->redirectAfterLogout($postLogoutRedirectUri, $state);
        }

        $activeRequest->attributes->set(
            'security.frontchannel_frame_origins',
            $this->originsOf($urls),
        );

        return Inertia::render('oauth/frontchannel-logout', [
            'iframeUrls' => $urls,
            'continueUrl' => $this->continueUrlAfterLogout($postLogoutRedirectUri, $state),
        ]);
    }

    private function isRegisteredPostLogoutRedirectUri(Client $client, string $uri): bool
    {
        $registered = $client->post_logout_redirect_uris ?? [];

        foreach ($registered as $registeredUri) {
            if (hash_equals((string) $registeredUri, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function redirectAfterLogout(?string $postLogoutRedirectUri, ?string $state): RedirectResponse|SymfonyResponse
    {
        if ($postLogoutRedirectUri === null) {
            return redirect()->route('login');
        }

        return redirect()->away($this->continueUrlAfterLogout($postLogoutRedirectUri, $state));
    }

    /**
     * Absolute URL the front-channel page (or a direct redirect) continues to.
     */
    private function continueUrlAfterLogout(?string $postLogoutRedirectUri, ?string $state): string
    {
        if ($postLogoutRedirectUri === null) {
            return route('login');
        }

        $separator = str_contains($postLogoutRedirectUri, '?') ? '&' : '?';

        return $state !== null
            ? $postLogoutRedirectUri.$separator.http_build_query(['state' => $state])
            : $postLogoutRedirectUri;
    }

    /**
     * CSP `frame-src` origins (`scheme://host[:port]`) for the iframe interstitial only.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function originsOf(array $urls): array
    {
        $origins = [];

        foreach ($urls as $url) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            $port = parse_url($url, PHP_URL_PORT);

            if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
                continue;
            }

            $origins[] = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
        }

        return array_values(array_unique($origins));
    }
}
