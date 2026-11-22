<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookEventEnum;
use App\Models\User;
use App\Services\WebhookDispatcherService;
use Illuminate\Validation\ValidationException;

/**
 * Updates an IdP user's name and email from the admin drawer. A null password keeps the
 * current hash. Activation and instance-admin flags are separate actions.
 * LDAP-sourced users cannot receive a local password (#187).
 *
 * Side effects: queues `user.updated` webhooks when endpoints are subscribed. Setting a
 * new password also revokes OAuth grants and ends every browser / OIDC session (#163).
 */
final class UpdateUserAction
{
    public function __construct(
        private readonly WebhookDispatcherService $webhookDispatcher,
        private readonly RevokeOnPasswordChangeAction $revokeOnPasswordChangeAction,
    ) {}

    /**
     * @throws ValidationException when setting a password on an LDAP-sourced user
     */
    public function execute(
        User $user,
        string $firstName,
        ?string $lastName,
        string $email,
        ?string $password,
        ?string $timezone = null,
        ?string $username = null,
        bool $hasUsername = false,
    ): User {
        if ($user->isLdapSourced() && $password !== null) {
            throw ValidationException::withMessages([
                'password' => __('This account\'s password is managed by the directory. Contact your administrator.'),
            ]);
        }

        $user->forceFill([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
        ]);

        if ($hasUsername) {
            $user->username = $username;
        }

        if ($timezone !== null) {
            $user->timezone = $timezone;
        }

        $didChangePassword = $password !== null;

        if ($didChangePassword) {
            $user->password = $password;
        }

        $user->save();

        if ($didChangePassword) {
            $this->revokeOnPasswordChangeAction->execute($user);
        }

        $this->webhookDispatcher->dispatch(WebhookEventEnum::UserUpdated, [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return $user;
    }
}
