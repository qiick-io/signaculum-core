<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soft-delete an IdP account after tearing down its live credentials.
 *
 * Deactivates first (Passport tokens revoked, browser sessions cleared, principal
 * deactivated, back-channel logout), then revokes every API key the user owns, then
 * soft-deletes. Self-service profile destroy and any future admin delete share this path
 * so a refresh token cannot mint access after the account is gone.
 */
final class DeleteUserAction
{
    public function __construct(
        private readonly DeactivateUserAction $deactivateUserAction,
    ) {}

    /**
     * Side effects: deactivation, API-key revocation, soft delete.
     *
     * @throws ValidationException when the target is the last active super-admin
     */
    public function execute(User $user): void
    {
        $this->deactivateUserAction->execute($user);

        DB::transaction(function () use ($user): void {
            $apiKeys = ApiKey::query()
                ->where('owner_id', $user->getKey())
                ->whereNull('revoked_at')
                ->get();

            foreach ($apiKeys as $apiKey) {
                $apiKey->forceFill([
                    'revoked_at' => now(),
                    'previous_key_hash' => null,
                    'previous_key_valid_until' => null,
                ])->save();

                activity('api-keys')
                    ->causedBy($user)
                    ->performedOn($apiKey)
                    ->event('revoked')
                    ->withProperties(['name' => $apiKey->name, 'scopes' => $apiKey->scopes, 'reason' => 'user_deleted'])
                    ->log('API key revoked on account deletion');
            }

            $user->delete();
        });
    }
}
