<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;

/**
 * Marks an in-browser re-authentication (OIDC Core §3.1.2.1 `max_age` / `prompt=login`,
 * or organisation MFA step-up when session `amr` lacks a second factor) so logout does
 * not fan out back-channel logout and the same `sid` is kept.
 *
 * A full Signaculum logout still ends SSO sessions; this path only asks the user to
 * prove they are still present for one authorization request.
 */
final class OidcReauthenticationService
{
    public const string FLAG_SESSION_KEY = 'oidc.reauthenticating';

    public const string PRESERVED_SID_SESSION_KEY = 'oidc.preserved_session_id';

    public function __construct(
        private readonly OidcSessionService $oidcSessionService,
    ) {}

    /**
     * Remember the current `sid` and set the flag before Auth::logout() so the
     * logout listener can skip back-channel fan-out.
     */
    public function begin(): void
    {
        if (! request()->hasSession()) {
            return;
        }

        $session = request()->session();
        $existingSid = $session->get(OidcSessionService::SESSION_KEY);

        $session->put(self::FLAG_SESSION_KEY, true);

        if (is_string($existingSid) && $existingSid !== '') {
            $session->put(self::PRESERVED_SID_SESSION_KEY, $existingSid);
        }
    }

    public function isInProgress(): bool
    {
        if (! request()->hasSession()) {
            return false;
        }

        return (bool) request()->session()->get(self::FLAG_SESSION_KEY, false);
    }

    /**
     * After session invalidate/regenerate, put the flag and preserved `sid` back
     * so the next login can restore them.
     */
    public function restoreAfterSessionRotation(?string $preservedSid): void
    {
        if (! request()->hasSession()) {
            return;
        }

        $session = request()->session();
        $session->put(self::FLAG_SESSION_KEY, true);

        if (is_string($preservedSid) && $preservedSid !== '') {
            $session->put(self::PRESERVED_SID_SESSION_KEY, $preservedSid);
        }
    }

    public function preservedOidcSessionId(): ?string
    {
        if (! request()->hasSession()) {
            return null;
        }

        $preservedSid = request()->session()->get(self::PRESERVED_SID_SESSION_KEY);

        return is_string($preservedSid) && $preservedSid !== '' ? $preservedSid : null;
    }

    /**
     * Keep the pre-reauth `sid` on the new login and clear the flag.
     */
    public function finishKeepingSid(User $user): void
    {
        if (! request()->hasSession()) {
            return;
        }

        $preservedSid = $this->preservedOidcSessionId();
        $session = request()->session();
        $session->forget([self::FLAG_SESSION_KEY, self::PRESERVED_SID_SESSION_KEY]);

        if ($preservedSid === null) {
            $this->oidcSessionService->regenerate($user);

            return;
        }

        $session->put(OidcSessionService::SESSION_KEY, $preservedSid);
        $this->oidcSessionService->currentOidcSessionId($user);
    }
}
