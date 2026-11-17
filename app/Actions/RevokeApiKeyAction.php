<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Revokes an API key at once: the next request with it (current or previous secret) gets
 * `401 invalid_token`. Key lookups are never cached, so there is nothing to invalidate.
 *
 * The row stays for the list and the audit trail; the rotation grace window is cleared, since a
 * revoked key has none. Side effects: writes a `revoked` entry in the `api-keys` activity log,
 * caused by the actor. Revoking a revoked key changes and logs nothing.
 */
final class RevokeApiKeyAction
{
    /**
     * @throws AuthorizationException when the actor may not revoke this key
     */
    public function execute(ApiKey $apiKey, User $actor): void
    {
        Gate::forUser($actor)->authorize('delete', $apiKey);

        if ($apiKey->isRevoked()) {
            return;
        }

        DB::transaction(function () use ($apiKey, $actor): void {
            $apiKey->forceFill([
                'revoked_at' => now(),
                'previous_key_hash' => null,
                'previous_key_valid_until' => null,
            ])->save();

            activity('api-keys')
                ->causedBy($actor)
                ->performedOn($apiKey)
                ->event('revoked')
                ->withProperties(['name' => $apiKey->name, 'scopes' => $apiKey->scopes])
                ->log('API key revoked');
        });
    }
}
