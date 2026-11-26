<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Qiick\Access\Contracts\ActiveUsers;

/**
 * The users that count as active for qiick/laravel-access' `SuperAdminGuard` ({@see ActiveUsers}):
 * not deactivated and not soft-deleted (the model's own scope), so the last active super-admin
 * can never be removed.
 */
final class AccessActiveUsersService implements ActiveUsers
{
    /**
     * @return Builder<Model>
     */
    public function query(): Builder
    {
        /** @var Builder<Model> $activeUsers */
        $activeUsers = User::query()->where('is_active', true);

        return $activeUsers;
    }
}
