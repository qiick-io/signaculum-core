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

    public function __construct(
        private readonly OidcSessionService $oidcSessionService,
        private readonly AuthenticationContextService $authenticationContextService,
    ) {}

    public function remember(string $clientId, ?string $nonce, bool $isAcrRequested = false): void
    {
        if (! request()->hasSession()) {
            return;
        }

        request()->session()->put(self::PENDING_SESSION_KEY, [
            'client_id' => $clientId,
            'nonce' => $nonce,
            'is_acr_requested' => $isAcrRequested,
        ]);
    }

    /**
     * Take the pending context for this client out of the session. A context saved for
     * another client is discarded, never reused.
     */
    public function pull(string $clientId): AuthorizationContextData
    {
        if (! request()->hasSession()) {
            return new AuthorizationContextData(
                nonce: null,
                authTime: null,
                oidcSessionId: null,
                authenticationMethods: $this->authenticationContextService->amrValues(),
                isAcrRequested: false,
            );
        }

        /** @var array{client_id?: string, nonce?: string|null, is_acr_requested?: bool}|null $pendingAuthorization */
        $pendingAuthorization = request()->session()->pull(self::PENDING_SESSION_KEY);
        $isSameClient = ($pendingAuthorization['client_id'] ?? null) === $clientId;

        $authTime = request()->session()->get(self::AUTH_TIME_SESSION_KEY);

        $user = request()->user();
        $oidcSessionId = $user instanceof User
            ? $this->oidcSessionService->currentOidcSessionId($user)
            : null;

        return new AuthorizationContextData(
            nonce: $isSameClient ? ($pendingAuthorization['nonce'] ?? null) : null,
            authTime: is_int($authTime) ? $authTime : null,
            oidcSessionId: $oidcSessionId,
            authenticationMethods: $this->authenticationContextService->amrValues(),
            isAcrRequested: $isSameClient && (bool) ($pendingAuthorization['is_acr_requested'] ?? false),
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
