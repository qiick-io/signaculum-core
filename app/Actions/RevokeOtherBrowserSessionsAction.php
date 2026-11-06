<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sign the user out everywhere except the current browser: delete the other session
 * rows and cycle the remember token, so remember-me cookies on other devices stop
 * working too.
 */
final class RevokeOtherBrowserSessionsAction
{
    public function execute(User $user, string $currentSessionId): void
    {
        DB::connection(config('session.connection'))
            ->table((string) config('session.table'))
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
