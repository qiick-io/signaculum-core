<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Services\InitialSuperAdminPasswordService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Creates the initial super-admin from config/super_admin.php.
 *
 * Outside `local` / `testing` the admin is created only when
 * `INITIAL_SUPER_ADMIN_PASSWORD` is set, is not the known default `password`,
 * and passes `Password::defaults()`. Never modifies an existing user
 * (decision 2026-11-19).
 */
class CreateSuperAdminSeeder extends Seeder
{
    /** @var list<string> */
    public const ENVIRONMENTS = ['local', 'testing'];

    public function run(InitialSuperAdminPasswordService $initialSuperAdminPassword): void
    {
        $password = config('super_admin.password');
        $passwordValue = is_string($password) ? $password : null;
        $reasons = $initialSuperAdminPassword->rejectionReasons($passwordValue);

        if ($reasons !== []) {
            $this->command?->warn('Skipped the initial super-admin: '.implode('; ', $reasons));

            return;
        }

        Artisan::call('signaculum:sync-permissions');

        $user = User::query()->firstOrNew(['email' => config('super_admin.email')]);

        if ($user->exists) {
            $this->command?->warn('Skipped the initial super-admin: a user with that email already exists.');

            return;
        }

        $user->forceFill([
            'first_name' => config('super_admin.first_name'),
            'last_name' => config('super_admin.last_name'),
            'password' => (string) $passwordValue,
            'email_verified_at' => now(),
            'is_active' => true,
        ])->save();

        $user->assignRole(RoleEnum::SuperAdmin->value);
    }
}
