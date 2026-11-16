<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\WebhookEventEnum;
use App\Models\User;
use App\Services\WebhookDispatcherService;

/**
 * Updates an IdP user's name and email from the admin drawer. A null password keeps the
 * current hash. Activation and instance-admin flags are separate actions.
 *
 * Side effects: queues `user.updated` webhooks when endpoints are subscribed.
 */
final class UpdateUserAction
{
    public function __construct(
        private readonly WebhookDispatcherService $webhookDispatcher,
    ) {}

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

        $this->webhookDispatcher->dispatch(WebhookEventEnum::UserUpdated, [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return $user;
    }
}
