<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SetInstanceAdminAction;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Grant or revoke the `super-admin` role for an existing user (replaces the former
 * `is_instance_admin` flag). Prefer `signaculum:create-user` for the first account.
 */
#[Signature('signaculum:make-admin {email : Email of an existing user} {--revoke : Remove the super-admin role}')]
#[Description('Grant or revoke the super-admin role for a user')]
final class MakeInstanceAdminCommand extends Command
{
    public function handle(SetInstanceAdminAction $setInstanceAdmin): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No user with that email.');

            return self::FAILURE;
        }

        $shouldGrant = ! (bool) $this->option('revoke');

        try {
            $setInstanceAdmin->execute($user, $shouldGrant);
        } catch (ValidationException $exception) {
            $this->components->error(collect($exception->errors())->flatten()->first() ?? 'Unable to change role.');

            return self::FAILURE;
        }

        $this->components->info($shouldGrant
            ? "{$user->email} is now a super-admin."
            : "{$user->email} is no longer a super-admin.");

        return self::SUCCESS;
    }
}
