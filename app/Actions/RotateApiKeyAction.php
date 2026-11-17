<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IssuedApiKeyData;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeySecretService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Issues a new secret for an existing key: same row, `uuid`, `key_prefix`, scopes and audit
 * trail. The replaced secret keeps working until `previous_key_valid_until`
 * (`api.key_rotation_grace_hours`), so an integration can switch without downtime.
 *
 * Rotation never changes what a key may do, and a revoked or expired key cannot be rotated back
 * to life. Side effects: updates the row inside a transaction holding a row lock, and writes a
 * `rotated` entry in the `api-keys` activity log, caused by the actor.
 */
final class RotateApiKeyAction
{
    public function __construct(
        private readonly ApiKeySecretService $apiKeySecretService,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not rotate this key, or it is no longer active
     */
    public function execute(ApiKey $apiKey, User $actor): IssuedApiKeyData
    {
        Gate::forUser($actor)->authorize('update', $apiKey);

        return DB::transaction(function () use ($apiKey, $actor): IssuedApiKeyData {
            // Re-read under a lock so a concurrent revoke or rotation is seen, not overwritten.
            $lockedApiKey = ApiKey::query()->lockForUpdate()->whereKey($apiKey->getKey())->firstOrFail();

            Gate::forUser($actor)->authorize('update', $lockedApiKey);

            $rotation = $this->apiKeySecretService->rotate(
                $lockedApiKey->key_prefix,
                $lockedApiKey->key_hash,
                CarbonImmutable::now(),
            );

            $lockedApiKey->forceFill([
                'key_hash' => $rotation->secret->keyHash,
                'previous_key_hash' => $rotation->previousKeyHash,
                'previous_key_valid_until' => $rotation->previousKeyValidUntil,
            ])->save();

            activity('api-keys')
                ->causedBy($actor)
                ->performedOn($lockedApiKey)
                ->event('rotated')
                ->withProperties([
                    'name' => $lockedApiKey->name,
                    'scopes' => $lockedApiKey->scopes,
                    'previous_key_valid_until' => $rotation->previousKeyValidUntil?->toIso8601String(),
                ])
                ->log('API key rotated');

            return new IssuedApiKeyData($lockedApiKey, $rotation->secret->plainTextToken);
        });
    }
}
