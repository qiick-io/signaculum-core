<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PermissionEnum;
use App\Enums\PrincipalTypeEnum;
use App\Models\ApiKey;
use App\Models\User;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Principals\PrincipalDirectory;
use Qiick\ApiKeys\AuthorizationDecision;
use Qiick\ApiKeys\Contracts\ApiKeyRecord;
use Qiick\ApiKeys\Contracts\KeyAuthorizer;
use Qiick\ApiKeys\KeyContext;
use Qiick\ApiKeys\RateLimitDecision;
use Qiick\ApiKeys\ResourceRestrictions;
use Qiick\ApiKeys\Scope;
use Qiick\ApiKeys\ScopeSet;

/**
 * Signaculum's rules on top of qiick/laravel-api-keys' verification ({@see KeyAuthorizer}).
 *
 * Admin API keys are owned by the singleton `instance_service` principal (decision 2026-11-22 /
 * #229). A key acts only while that principal is active and at least one active instance admin
 * exists, and it then acts as an instance admin: the creator while they still are one, otherwise
 * any other ({@see actingAdminFor()}). Demoting the creator alone does not revoke the key.
 * Instance keys carry their powers in their scopes alone, so the scope ceiling is the scopes the
 * product knows; Signaculum stores no resource restrictions, so any it finds is refused.
 */
final class ApiKeyAuthorizerService implements KeyAuthorizer
{
    public function __construct(
        private readonly InstanceAdminPresenceService $instanceAdminPresenceService,
        private readonly EffectivePermissionResolver $effectivePermissionResolver,
        private readonly PrincipalDirectory $principalDirectory,
    ) {}

    public function authorizeActor(ApiKeyRecord $record, KeyContext $context): AuthorizationDecision
    {
        if (! $record instanceof ApiKey) {
            return AuthorizationDecision::deny(__('API key owner may not use the API.'));
        }

        $ownerPrincipal = $record->ownerPrincipal;

        if (
            $ownerPrincipal === null
            || $ownerPrincipal->type !== PrincipalTypeEnum::InstanceService->value
            // The package's live check: not deactivated, not expired, and its owner chain active.
            || ! $this->principalDirectory->isActive((string) $ownerPrincipal->getKey())
            || $this->actingAdminFor($record) === null
        ) {
            return AuthorizationDecision::deny(__('API key owner may not use the API.'));
        }

        return AuthorizationDecision::allow();
    }

    public function authorizeScopes(ApiKeyRecord $record, ScopeSet $granted, ?Scope $required, KeyContext $context): AuthorizationDecision
    {
        return AuthorizationDecision::allow();
    }

    /**
     * @param  list<string>  $knownScopes
     * @return list<string>
     */
    public function scopesCreatorMayGrant(ApiKeyRecord $record, array $knownScopes, KeyContext $context): array
    {
        return $knownScopes;
    }

    /**
     * Empty: the package applies the key's own per-minute, per-day and per-month limits, and
     * `api-keys.default_rate_limit_per_minute` when the key has none.
     *
     * @return list<RateLimitDecision>
     */
    public function rateLimit(ApiKeyRecord $record, KeyContext $context): array
    {
        return [];
    }

    public function authorizeResources(ApiKeyRecord $record, ResourceRestrictions $resources, KeyContext $context): AuthorizationDecision
    {
        return AuthorizationDecision::deny(__('API key restrictions are not supported.'));
    }

    /**
     * The instance admin a key acts as: its creator while they are still an active admin who may
     * manage API keys, otherwise the first other active instance admin; null when there is none.
     */
    public function actingAdminFor(ApiKey $apiKey): ?User
    {
        $creator = $apiKey->creator;

        if (
            $creator instanceof User
            && $creator->is_active
            && $this->effectivePermissionResolver->hasPermission($creator, PermissionEnum::CanManageApiKeys->value)
        ) {
            return $creator;
        }

        return $this->instanceAdminPresenceService->firstActiveInstanceAdmin();
    }
}
