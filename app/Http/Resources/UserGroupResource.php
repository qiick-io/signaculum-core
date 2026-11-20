<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin groups list and the create/edit drawer payload.
 *
 * @mixin UserGroup
 */
final class UserGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var UserGroup $group */
        $group = $this->resource;

        $users = $group->relationLoaded('users')
            ? $group->users->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()->all()
            : [];

        $roles = $group->relationLoaded('roles')
            ? $group->roles->map(fn ($role): array => [
                'id' => $role->id,
                'name' => $role->name,
            ])->values()->all()
            : [];

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'users_count' => $group->users_count ?? $group->users->count(),
            'roles_count' => $group->roles_count ?? $group->roles->count(),
            'users' => $users,
            'user_ids' => array_map(static fn (array $user): int => (int) $user['id'], $users),
            'roles' => $roles,
            'role_ids' => array_map(static fn (array $role): int => (int) $role['id'], $roles),
            'deleted_at' => $group->deleted_at?->toIso8601String(),
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
        ];
    }
}
