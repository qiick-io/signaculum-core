<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\RevokeOnPasswordChangeAction;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Fortify action that validates and stores a new password after a reset link.
 * Keeps the upstream Fortify class name; wiring lives in FortifyServiceProvider.
 *
 * Side effects: revokes OAuth grants and ends every browser / OIDC session (#163).
 */
class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly RevokeOnPasswordChangeAction $revokeOnPasswordChangeAction,
    ) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException when the account is LDAP-sourced (#187)
     */
    public function reset(User $user, array $input): void
    {
        if ($user->isLdapSourced()) {
            throw ValidationException::withMessages([
                'email' => __('This account\'s password is managed by the directory. Contact your administrator.'),
            ]);
        }

        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        $this->revokeOnPasswordChangeAction->execute($user);
    }
}
