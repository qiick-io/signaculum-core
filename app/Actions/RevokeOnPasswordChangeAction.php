<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * After a local password change (#163 / OWASP session management): revoke OAuth
 * grants and end browser / OIDC sessions so a stolen refresh token or another
 * browser cannot keep the old credential's access.
 *
 * Self-service keeps `$keepSessionId`; password reset and admin-set passwords
 * pass null and end every session. LDAP-sourced accounts have no local password
 * path that reaches here; this is a no-op for them.
 */
final class RevokeOnPasswordChangeAction
{
    public function __construct(
        private readonly RevokeUserOidcGrantsAction $revokeUserOidcGrantsAction,
        private readonly RevokeOtherBrowserSessionsAction $revokeOtherBrowserSessionsAction,
        private readonly EndAllUserBrowserSessionsAction $endAllUserBrowserSessionsAction,
    ) {}

    public function execute(User $user, ?string $keepSessionId = null): void
    {
        if ($user->isLdapSourced()) {
            return;
        }

        $this->revokeUserOidcGrantsAction->execute($user);

        if ($keepSessionId !== null) {
            $this->revokeOtherBrowserSessionsAction->execute($user, $keepSessionId);

            return;
        }

        $this->endAllUserBrowserSessionsAction->execute($user);
    }
}
