<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Make an existing user an instance admin (or take it away with --revoke). The first admin of
 * a fresh install is created this way, since nobody in the UI can grant it yet.
 */
#[Signature('signaculum:make-admin {email : Email of an existing user} {--revoke : Remove the instance admin flag}')]
#[Description('Grant or revoke instance admin rights for a user')]
final class MakeInstanceAdminCommand extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No user with that email.');

            return self::FAILURE;
        }

        $isInstanceAdmin = ! (bool) $this->option('revoke');
        $user->forceFill(['is_instance_admin' => $isInstanceAdmin])->save();

        $this->components->info($isInstanceAdmin
            ? "{$user->email} is now an instance admin."
            : "{$user->email} is no longer an instance admin.");

        return self::SUCCESS;
    }
}
