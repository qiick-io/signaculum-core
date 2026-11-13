<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * One row of the admin users list and the create/edit drawer payload.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     first_name: string,
     *     last_name: string|null,
     *     name: string,
     *     email: string,
     *     is_active: bool,
     *     is_instance_admin: bool,
     *     has_two_factor: bool,
     *     email_verified_at: string|null,
     *     created_at: string|null,
     *     updated_at: string|null,
     *     can_update: bool
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => (bool) $user->is_active,
            'is_instance_admin' => (bool) $user->is_instance_admin,
            'has_two_factor' => $user->hasEnabledTwoFactorAuthentication(),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
            'can_update' => Gate::allows('update', $user),
        ];
    }
}
