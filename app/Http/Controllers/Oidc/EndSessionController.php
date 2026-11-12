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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `end_session_endpoint` (OIDC RP-Initiated Logout 1.0).
 *
 * GET|POST `/oauth/end-session`. When the hint is missing or belongs to another user,
 * show a confirmation page before ending the Signaculum session.
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
        $hintUser = $hintSubject !== null ? $idTokenHintValidatorService->userForSubject($hintSubject) : null;

        $needsConfirmation = ! $isHintValid
            || $authenticatedUser === null
            || $hintUser === null
            || $authenticatedUser->isNot($hintUser);

        if ($needsConfirmation && $authenticatedUser !== null && ! $request->boolean('confirmed')) {
            return Inertia::render('oauth/logout', [
                'clientId' => $clientId,
                'postLogoutRedirectUri' => $postLogoutRedirectUri,
                'state' => $state,
                'idTokenHint' => $idTokenHint !== '' ? $idTokenHint : null,
            ]);
        }

        if ($needsConfirmation && $authenticatedUser === null && ! $isHintValid) {
            return $this->redirectAfterLogout($postLogoutRedirectUri, $state);
        }

        $userToLogout = $hintUser ?? $authenticatedUser;

        if ($userToLogout instanceof User) {
            $endOidcSessionAction->execute(
                user: $userToLogout,
                oidcSessionId: $hintOidcSessionId,
                shouldLogoutWeb: true,
            );
        }

        return $this->redirectAfterLogout($postLogoutRedirectUri, $state);
    }

    public function confirm(Request $request, EndOidcSessionAction $endOidcSessionAction): RedirectResponse|SymfonyResponse
    {
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

        if ($user instanceof User) {
            $endOidcSessionAction->execute(user: $user, shouldLogoutWeb: true);
        }

        return $this->redirectAfterLogout($postLogoutRedirectUri, $state);
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

        $separator = str_contains($postLogoutRedirectUri, '?') ? '&' : '?';
        $target = $state !== null
            ? $postLogoutRedirectUri.$separator.http_build_query(['state' => $state])
            : $postLogoutRedirectUri;

        return redirect()->away($target);
    }
}
