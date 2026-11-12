<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;

/**
 * Creates an IdP user from the admin drawer. The email counts as verified because an
 * instance admin created the account. New users start active; deactivation is a later action.
 */
final class CreateUserAction
{
    public function execute(string $firstName, ?string $lastName, string $email, string $password): User
    {
        $user = (new User)->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $user->save();

        return $user;
    }
}
