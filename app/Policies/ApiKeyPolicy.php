<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApiKey;
use App\Models\User;

/**
 * Admin REST API keys act as the instance admin who owns them, so only an active instance admin
 * manages keys, and only their own: one admin cannot rotate or revoke another admin's key.
 * `update` is rotation, allowed on active keys only.
 */
final class ApiKeyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->isActiveInstanceAdmin($actor);
    }

    public function create(User $actor): bool
    {
        return $this->isActiveInstanceAdmin($actor);
    }

    public function update(User $actor, ApiKey $apiKey): bool
    {
        return $this->isActiveInstanceAdmin($actor)
            && $apiKey->owner_id === $actor->getKey()
            && $apiKey->isActive();
    }

    public function delete(User $actor, ApiKey $apiKey): bool
    {
        return $this->isActiveInstanceAdmin($actor)
            && $apiKey->owner_id === $actor->getKey();
    }

    private function isActiveInstanceAdmin(User $actor): bool
    {
        return (bool) $actor->is_instance_admin && (bool) $actor->is_active;
    }
}
