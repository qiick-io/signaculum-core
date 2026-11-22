<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Events\ApiKeyCreatorDeletedEvent;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\InstanceAdminDemotionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soft-delete an IdP account after tearing down its live credentials.
 *
 * Deactivates first (Passport tokens revoked, browser sessions cleared, membership
 * principals deactivated, back-channel logout), then revokes every Admin API key this
 * user created (`created_by`), then soft-deletes. Self-service profile destroy and any
 * future admin delete share this path so a refresh token cannot mint access after the
 * account is gone.
 */
final class DeleteUserAction
{
    public function __construct(
        private readonly DeactivateUserAction $deactivateUserAction,
        private readonly InstanceAdminDemotionService $instanceAdminDemotionService,
    ) {}

    /**
     * Side effects: deactivation, API-key revocation, soft delete; one InstanceAdminDemotedEvent
     * (`deleted`, never also `deactivated`) when the user was an instance admin; and
     * ApiKeyCreatorDeletedEvent whenever a key was revoked, admin or not, so the other admins
     * always hear which keys the deletion revoked.
     *
     * @throws ValidationException when the target is the last active super-admin
     */
    public function execute(User $user): void
    {
        $this->instanceAdminDemotionService->track(
            [$user->getKey()],
            InstanceAdminDemotionReasonEnum::Deleted,
            fn () => $this->delete($user),
        );
    }

    private function delete(User $user): void
    {
        $this->deactivateUserAction->execute($user);

        $revokedApiKeyIds = DB::transaction(function () use ($user): array {
            $apiKeys = ApiKey::query()
                ->where('created_by', $user->getKey())
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

            return array_values(array_map(intval(...), $apiKeys->modelKeys()));
        });

        if ($revokedApiKeyIds !== []) {
            ApiKeyCreatorDeletedEvent::dispatch($user, $revokedApiKeyIds);
        }
    }
}
