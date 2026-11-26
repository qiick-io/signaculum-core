<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Qiick\Access\Principals\PrincipalDeactivated;
use Qiick\ApiKeys\RevocationReason;

/**
 * A key whose owner principal is deactivated stops at once (qiick/laravel-access adoption guide:
 * "revoke their API keys in a listener", api-keys reason `owner`): the principal's own keys and
 * those of every principal it owns, active or not.
 *
 * Side effects: sets `revoked_at` and `revoked_reason` (`owner`) and writes a `revoked` entry in the
 * `api-keys` activity log per key, caused by the signed-in user when there is one.
 */
final class RevokeDeactivatedPrincipalApiKeysListener
{
    public function handle(PrincipalDeactivated $event): void
    {
        $principalIds = [(string) $event->principal->getKey(), ...$event->ownedIds];
        $actor = Auth::user();

        $apiKeys = ApiKey::query()
            ->whereIn('owner_principal_id', $principalIds)
            ->whereNull('revoked_at')
            ->get();

        foreach ($apiKeys as $apiKey) {
            $apiKey->forceFill([
                'revoked_at' => Date::now(),
                'revoked_reason' => RevocationReason::Owner->value,
                'previous_key_hash' => null,
                'previous_key_valid_until' => null,
            ])->save();

            $activity = activity('api-keys')
                ->performedOn($apiKey)
                ->event('revoked')
                ->withProperties(['name' => $apiKey->name, 'scopes' => $apiKey->scopes, 'reason' => RevocationReason::Owner->value]);

            if ($actor instanceof User) {
                $activity->causedBy($actor);
            }

            $activity->log('API key revoked');
        }
    }
}
