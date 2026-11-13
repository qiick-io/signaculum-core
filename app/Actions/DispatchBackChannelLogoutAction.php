<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\SendBackChannelLogoutJob;
use App\Models\Client;
use App\Models\ClientSession;
use App\Models\User;
use Illuminate\Support\Facades\Date;

/**
 * Queue a back-channel `logout_token` for every client that still has an open
 * session for this user (optionally limited to one `sid` or one client).
 *
 * One failing delivery never blocks the others: each client gets its own job
 * (OIDC Back-Channel Logout 1.0 §2.4–2.8).
 */
final class DispatchBackChannelLogoutAction
{
    /**
     * @param  string|null  $oidcSessionId  when set, only that browser session; otherwise every open client session for the user
     * @param  string|null  $clientId  when set, only that client (e.g. "remove access")
     */
    public function execute(User $user, ?string $oidcSessionId = null, ?string $clientId = null): void
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

        $clientSessions = $query->get();

        foreach ($clientSessions as $clientSession) {
            $client = $clientSession->client;

            if (! $client instanceof Client || blank($client->backchannel_logout_uri)) {
                $clientSession->forceFill(['ended_at' => Date::now()])->save();

                continue;
            }

            // Always send this client session's sid when we have it (Back-Channel
            // Logout 1.0 §2.4). is_backchannel_logout_session_required only tells
            // the RP that sid will be present; it must not drop sid from the token.
            SendBackChannelLogoutJob::dispatch(
                clientId: $client->getKey(),
                backchannelLogoutUri: $client->backchannel_logout_uri,
                subject: $user->subject,
                oidcSessionId: $clientSession->oidc_session_id,
            );

            $clientSession->forceFill(['ended_at' => Date::now()])->save();
        }
    }
}
