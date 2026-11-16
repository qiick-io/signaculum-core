<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\WebhookEventEnum;
use App\Models\User;
use App\Services\SettingsRepositoryService;
use App\Services\WebhookDispatcherService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Features;

/**
 * Fortify action that registers a new user when self-registration is enabled.
 *
 * Side effects: queues `user.created` webhooks when endpoints are subscribed.
 */
class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        if (! Features::enabled(Features::registration())
            || ! app(SettingsRepositoryService::class)->isRegistrationEnabled()) {
            throw ValidationException::withMessages([
                'email' => __('Registration is currently disabled.'),
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'] ?? null,
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        app(WebhookDispatcherService::class)->dispatch(WebhookEventEnum::UserCreated, [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return $user;
    }
}
