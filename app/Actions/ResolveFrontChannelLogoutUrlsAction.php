<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Client;
use App\Models\ClientSession;
use App\Models\User;

/**
 * Build iframe `src` URLs for OIDC Front-Channel Logout 1.0 §2.
 *
 * One URL per open client session that registered `frontchannel_logout_uri`.
 * When the client requires session identification
 * (`is_frontchannel_logout_session_required`), appends both `iss` and `sid`
 * (Front-Channel Logout 1.0 §2–§3); existing query components are retained.
 */
final class ResolveFrontChannelLogoutUrlsAction
{
    /**
     * @param  string|null  $oidcSessionId  when set, only that browser session
     * @param  string|null  $clientId  when set, only that client
     * @return list<string>
     */
    public function execute(User $user, ?string $oidcSessionId = null, ?string $clientId = null): array
    {
        $query = ClientSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->with('client');

        if ($oidcSessionId !== null) {
            $query->where('oidc_session_id', $oidcSessionId);
        }

        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }

        $iframeUrls = [];
        $issuer = rtrim((string) config('oidc.issuer'), '/');

        foreach ($query->get() as $clientSession) {
            $client = $clientSession->client;

            if (! $client instanceof Client || blank($client->frontchannel_logout_uri)) {
                continue;
            }

            $iframeUrls[] = $this->logoutUriForClient(
                registeredUri: $client->frontchannel_logout_uri,
                isSessionRequired: (bool) $client->is_frontchannel_logout_session_required,
                issuer: $issuer,
                oidcSessionId: $clientSession->oidc_session_id,
            );
        }

        return array_values(array_unique($iframeUrls));
    }

    /**
     * Front-Channel Logout 1.0 §2: retain any registered query; if `iss` or `sid`
     * is added, both MUST be present.
     */
    private function logoutUriForClient(
        string $registeredUri,
        bool $isSessionRequired,
        string $issuer,
        string $oidcSessionId,
    ): string {
        if (! $isSessionRequired) {
            return $registeredUri;
        }

        $separator = str_contains($registeredUri, '?') ? '&' : '?';

        return $registeredUri.$separator.http_build_query([
            'iss' => $issuer,
            'sid' => $oidcSessionId,
        ]);
    }
}
