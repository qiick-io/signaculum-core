<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Notifications\SecondFactorChangedNotification;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * Make every second-factor change visible: an `auth` activity entry and a mail to the account
 * owner, for TOTP enable / confirm / disable, recovery-code regeneration, and passkey
 * registration / deletion (#168).
 *
 * Managing factors needs only the password (`password.confirm`), so someone who knows it can
 * enrol their own factor and then satisfy organisation MFA. This does not stop that; it makes
 * sure the owner hears about it. The admin TOTP reset goes through Fortify's disable action
 * (UI or admin API) and is covered too, with the admin as the causer.
 *
 * Side effects: writes an activity entry (passkey id and name only, never secrets) and queues
 * SecondFactorChangedNotification.
 */
final class RecordSecondFactorChangeListener
{
    public function handle(
        TwoFactorAuthenticationEnabled|TwoFactorAuthenticationConfirmed|TwoFactorAuthenticationDisabled|RecoveryCodesGenerated|PasskeyRegistered|PasskeyDeleted $event,
    ): void {
        $user = $event->user;

        if (! $user instanceof User) {
            return;
        }

        $secondFactorChange = match (true) {
            $event instanceof TwoFactorAuthenticationEnabled => ActivityEventEnum::TwoFactorEnabled,
            $event instanceof TwoFactorAuthenticationConfirmed => ActivityEventEnum::TwoFactorConfirmed,
            $event instanceof TwoFactorAuthenticationDisabled => ActivityEventEnum::TwoFactorDisabled,
            $event instanceof RecoveryCodesGenerated => ActivityEventEnum::RecoveryCodesRegenerated,
            $event instanceof PasskeyRegistered => ActivityEventEnum::PasskeyRegistered,
            $event instanceof PasskeyDeleted => ActivityEventEnum::PasskeyDeleted,
        };

        // The default guard: the web user, or the API key owner on /api/v1 (shouldUse('api-key')).
        $signedInUser = Auth::user();

        activity()
            ->causedBy($signedInUser instanceof User ? $signedInUser : $user)
            ->performedOn($user)
            ->useLog('auth')
            ->event($secondFactorChange->value)
            ->withProperties($event instanceof PasskeyRegistered || $event instanceof PasskeyDeleted ? [
                'passkey_id' => $event->passkey->getKey(),
                'passkey_name' => $event->passkey->name,
            ] : [])
            ->log('Second factor changed');

        $user->notify(new SecondFactorChangedNotification($secondFactorChange));
    }
}
