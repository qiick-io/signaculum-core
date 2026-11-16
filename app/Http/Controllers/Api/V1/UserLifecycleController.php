<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\DeactivateUserAction;
use App\Actions\ResetUserTwoFactorAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Admin REST API: deactivate a user and reset two-factor authentication.
 */
final class UserLifecycleController extends Controller
{
    public function deactivate(User $user, DeactivateUserAction $deactivateUserAction): UserResource
    {
        Gate::authorize('update', $user);

        $deactivateUserAction->execute($user);

        return new UserResource($user->refresh());
    }

    public function resetTwoFactor(User $user, ResetUserTwoFactorAction $resetUserTwoFactorAction): UserResource
    {
        Gate::authorize('update', $user);

        $resetUserTwoFactorAction->execute($user);

        return new UserResource($user->refresh());
    }
}
