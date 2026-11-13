<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Grant or revoke the instance-admin flag. Never leaves the instance with zero
 * instance admins (same invariant as {@see MakeInstanceAdminCommand}).
 */
final class SetInstanceAdminAction
{
    /**
     * @throws ValidationException when revoking the last instance admin
     */
    public function execute(User $user, bool $isInstanceAdmin): void
    {
        if (! $isInstanceAdmin && $user->is_instance_admin && $this->isLastInstanceAdmin($user)) {
            throw ValidationException::withMessages([
                'user' => __('Cannot revoke the last instance admin.'),
            ]);
        }

        $user->forceFill(['is_instance_admin' => $isInstanceAdmin])->save();
    }

    private function isLastInstanceAdmin(User $user): bool
    {
        return User::query()
            ->where('is_instance_admin', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }
}
