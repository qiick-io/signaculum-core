<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\User;

/**
 * Instance admins manage every OIDC application on this IdP.
 */
final class ApplicationPolicy
{
    public function viewAny(User $actor): bool
    {
        return (bool) $actor->is_instance_admin;
    }

    public function create(User $actor): bool
    {
        return (bool) $actor->is_instance_admin;
    }

    public function update(User $actor, Client $application): bool
    {
        return (bool) $actor->is_instance_admin;
    }

    public function delete(User $actor, Client $application): bool
    {
        return (bool) $actor->is_instance_admin;
    }
}
