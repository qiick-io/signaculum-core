<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\AuthorizationContextData;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Carries the OpenID Connect context of an authorization request across the consent
 * screen, in the user's session.
 *
 * The `nonce` arrives on `GET /oauth/authorize`, but the code may only be issued later
 * when the user approves (`POST /oauth/authorize`), and league's serialized request has
 * no room for it. Passport keeps one pending request per session too (its `authToken`),
 * so a second authorization in another tab replaces both and the first one fails safely.
 */
final class PendingAuthorizationService
{
    private const string PENDING_SESSION_KEY = 'oidc.pending_authorization';

    private const string AUTH_TIME_SESSION_KEY = 'oidc.auth_time';

    private const string MAX_AGE_REQUESTED_SESSION_KEY = 'oidc.max_age_requested';

    public function __construct(
        private readonly OidcSessionService $oidcSessionService,
    ) {}

    public function remember(string $clientId, ?string $nonce): void
    {
        if (! request()->hasSession()) {
            return;
        }

        request()->session()->put(self::PENDING_SESSION_KEY, [
            'client_id' => $clientId,
            'nonce' => $nonce,
        ]);
    }

    /**
     * Mark that this authorization request carried `max_age` (OIDC Core §3.1.2.1):
     * the resulting id_token must include `auth_time`.
     */
    public function rememberMaxAgeRequested(): void
    {
        if (request()->hasSession()) {
            request()->session()->put(self::MAX_AGE_REQUESTED_SESSION_KEY, true);
        }
    }

    /**
     * Unix timestamp of the last interactive login in this browser session, if known.
     */
    public function authenticationTime(): ?int
    {
        if (! request()->hasSession()) {
            return null;
        }

        $authenticationTime = request()->session()->get(self::AUTH_TIME_SESSION_KEY);

        return is_int($authenticationTime) ? $authenticationTime : null;
    }

    /**
     * Take the pending context for this client out of the session. A context saved for
     * another client is discarded, never reused.
     *
     * When `max_age` was on the authorization request and `auth_time` is somehow still
     * missing after a successful login, fall back to "now" so the id_token still carries
     * the claim the spec requires (OIDC Core §3.1.2.1).
     */
    public function pull(string $clientId): AuthorizationContextData
    {
        if (! request()->hasSession()) {
            return new AuthorizationContextData(nonce: null, authTime: null, oidcSessionId: null);
        }

        /** @var array{client_id?: string, nonce?: string|null}|null $pendingAuthorization */
        $pendingAuthorization = request()->session()->pull(self::PENDING_SESSION_KEY);
        $isSameClient = ($pendingAuthorization['client_id'] ?? null) === $clientId;
        $isMaxAgeRequested = (bool) request()->session()->pull(self::MAX_AGE_REQUESTED_SESSION_KEY, false);

        $authTime = $this->authenticationTime();

        if ($authTime === null && $isMaxAgeRequested) {
            $authTime = Date::now()->getTimestamp();
        }

        $user = request()->user();
        $oidcSessionId = $user instanceof User
            ? $this->oidcSessionService->currentOidcSessionId($user)
            : null;

        return new AuthorizationContextData(
            nonce: $isSameClient ? ($pendingAuthorization['nonce'] ?? null) : null,
            authTime: $authTime,
            oidcSessionId: $oidcSessionId,
        );
    }

    /**
     * Record when the user authenticated (OIDC Core `auth_time`).
     */
    public function recordAuthentication(): void
    {
        if (request()->hasSession()) {
            request()->session()->put(self::AUTH_TIME_SESSION_KEY, Date::now()->getTimestamp());
        }
    }
}
