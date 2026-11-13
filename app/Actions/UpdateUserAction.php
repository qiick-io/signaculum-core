<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Updates an IdP user's name and email from the admin drawer. A null password keeps the
 * current hash. Activation and instance-admin flags are separate actions.
 */
final class UpdateUserAction
{
    public function execute(User $user, string $firstName, ?string $lastName, string $email, ?string $password): User
    {
        $user->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ]);

        if ($password !== null) {
            $user->password = $password;
        }

        $user->save();

        return $user;
    }
}
