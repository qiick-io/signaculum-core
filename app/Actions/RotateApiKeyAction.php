<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IssuedApiKeyData;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\ApiKeyLifetimeService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Qiick\ApiKeys\Contracts\Clock;
use Qiick\ApiKeys\RotationService;
use Qiick\ApiKeys\TokenCodec;

/**
 * Issues a new secret for an existing key: same row, `uuid`, `key_prefix`, scopes and audit
 * trail. The replaced secret keeps working until `previous_key_valid_until`
 * (`api.key_rotation_grace_hours`), so an integration can switch without downtime.
 *
 * Rotation never changes what a key may do, and a revoked or expired key cannot be rotated back
 * to life. It sets a new expiry within the maximum lifetime counted from the rotation
 * ({@see ApiKeyLifetimeService}), so rotating never extends a key past that bound.
 *
 * Side effects: updates the row inside a transaction holding a row lock, and writes a `rotated`
 * entry in the `api-keys` activity log, caused by the actor.
 */
final class RotateApiKeyAction
{
    public function __construct(
        private readonly RotationService $rotationService,
        private readonly TokenCodec $tokenCodec,
        private readonly Clock $clock,
        private readonly ApiKeyLifetimeService $apiKeyLifetimeService,
    ) {}

    /**
     * @throws AuthorizationException when the actor may not rotate this key, or it is no longer active
     * @throws InvalidArgumentException when the new expiry is not in the future or past the maximum lifetime
     */
    public function execute(ApiKey $apiKey, User $actor, CarbonImmutable $expiresAt): IssuedApiKeyData
    {
        Gate::forUser($actor)->authorize('update', $apiKey);

        $rotatedAt = CarbonImmutable::now();
        $expiresAt = $this->apiKeyLifetimeService->allowedExpiry($expiresAt, $rotatedAt);

        return DB::transaction(function () use ($apiKey, $actor, $expiresAt): IssuedApiKeyData {
            // Re-read under a lock so a concurrent revoke or rotation is seen, not overwritten.
            $lockedApiKey = ApiKey::query()->lockForUpdate()->whereKey($apiKey->getKey())->firstOrFail();

            Gate::forUser($actor)->authorize('update', $lockedApiKey);

            $rotation = $this->rotationService->rotate(
                $lockedApiKey,
                $this->tokenCodec,
                $this->clock,
                RotationService::normalizeGraceHours(config('api-keys.rotation_grace_hours'), $this->rotationService->maxGraceHours()),
                $expiresAt->toDateTimeImmutable(),
            );

            // The package's contract: write every field the rotation returns, unconditionally.
            // A new expiry earns a new expiry notice, so the notice stamps are cleared too.
            $lockedApiKey->forceFill([
                'key_prefix' => $rotation->newKeyPrefix,
                'key_hash' => $rotation->newKeyHash,
                'token_version' => $rotation->tokenVersion,
                'previous_key_prefix' => $rotation->previousKeyPrefix,
                'previous_key_hash' => $rotation->previousKeyHash,
                'previous_key_valid_until' => $rotation->previousKeyValidUntil,
                'last_rotated_at' => $rotation->lastRotatedAt,
                'expires_at' => $expiresAt,
                'expiry_notified_at' => null,
                'expiry_notice_claimed_at' => null,
            ])->save();

            activity('api-keys')
                ->causedBy($actor)
                ->performedOn($lockedApiKey)
                ->event('rotated')
                ->withProperties([
                    'name' => $lockedApiKey->name,
                    'scopes' => $lockedApiKey->scopes,
                    'previous_key_valid_until' => $rotation->previousKeyValidUntil?->format(DATE_ATOM),
                    'expires_at' => $expiresAt->toIso8601String(),
                ])
                ->log('API key rotated');

            return new IssuedApiKeyData($lockedApiKey, $rotation->newPlainText);
        });
    }
}
