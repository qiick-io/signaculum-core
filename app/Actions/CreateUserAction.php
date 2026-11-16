<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookEventEnum;
use App\Models\User;
use App\Services\WebhookDispatcherService;

/**
 * Creates an IdP user from the admin drawer. The email counts as verified because an
 * instance admin created the account. New users start active; deactivation is a later action.
 *
 * Side effects: queues `user.created` webhooks when endpoints are subscribed.
 */
final class CreateUserAction
{
    public function __construct(
        private readonly WebhookDispatcherService $webhookDispatcher,
    ) {}

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

        $this->webhookDispatcher->dispatch(WebhookEventEnum::UserCreated, [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return $user;
    }
}
