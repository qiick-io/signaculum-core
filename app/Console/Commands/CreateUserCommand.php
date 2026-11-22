<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CreateUserAction;
use App\Enums\RoleEnum;
use App\Models\Role;
use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Creates an IdP user from the CLI. The way in when mail is not configured: there is no
 * public sign-up. The email is marked verified because the operator created the account.
 * The first user defaults to super-admin; later users default to reader.
 */
#[Signature('signaculum:create-user
    {--first-name= : First name}
    {--last-name= : Last name (optional)}
    {--email= : Login email}
    {--password= : Password (required with --no-interaction)}
    {--role= : Role name (default: super-admin for the first user, reader afterwards)}')]
#[Description('Create a Signaculum user (password prompted unless passed)')]
final class CreateUserCommand extends Command
{
    public function handle(
        CreateUserAction $createUser,
        InitialSuperAdminPasswordService $initialSuperAdminPassword,
    ): int {
        $this->callSilently('signaculum:sync-permissions');

        $defaultRole = User::query()->exists() ? RoleEnum::Reader->value : RoleEnum::SuperAdmin->value;
        $roles = Role::query()->orderBy('name')->pluck('name')->all();

        if ($roles === []) {
            $this->components->error('No roles found after sync-permissions.');

            return self::FAILURE;
        }

        $isNonInteractive = (bool) $this->option('no-interaction') || ! $this->input->isInteractive();

        $firstName = $this->option('first-name');
        $lastName = $this->option('last-name');
        $email = $this->option('email');
        $passwordValue = $this->option('password');
        $role = $this->option('role');

        if (! $isNonInteractive) {
            $firstName = is_string($firstName) && $firstName !== '' ? $firstName : text('First name', required: true);
            $lastName = is_string($lastName) ? $lastName : text('Last name');
            $email = is_string($email) && $email !== '' ? $email : text('Email', required: true);
            $passwordValue = is_string($passwordValue) && $passwordValue !== '' ? $passwordValue : password('Password', required: true);
            $role = is_string($role) && $role !== '' ? $role : select('Role', $roles, default: $defaultRole);
        }

        $passwordString = is_string($passwordValue) ? $passwordValue : null;
        $passwordReasons = $initialSuperAdminPassword->rejectionReasons($passwordString);

        if ($passwordReasons !== []) {
            foreach ($passwordReasons as $reason) {
                $this->components->error($reason);
            }

            return self::FAILURE;
        }

        $input = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => Str::lower((string) $email),
            'password' => $passwordString,
            'role' => $role ?: $defaultRole,
        ];

        $validator = Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
            'role' => ['required', 'string', Rule::in($roles)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();
        $user = $createUser->execute(
            $validated['first_name'],
            ($validated['last_name'] ?? '') !== '' ? $validated['last_name'] : null,
            $validated['email'],
            $validated['password'],
        );
        $user->assignRole($validated['role']);

        $this->components->info("Created user {$user->email} with role {$validated['role']}.");

        return self::SUCCESS;
    }
}
