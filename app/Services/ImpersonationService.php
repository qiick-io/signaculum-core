<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Session-backed impersonation state for instance admins.
 *
 * The web session keeps the actor id and an absolute expiry. Auth::user() is
 * the target while the keys are present; stop or expiry restores the actor.
 */
final class ImpersonationService
{
    public const SESSION_ACTOR_ID = 'impersonation.actor_id';

    public const SESSION_TARGET_ID = 'impersonation.target_id';

    public const SESSION_EXPIRES_AT = 'impersonation.expires_at';

    /**
     * Whether the current request is acting as someone else.
     */
    public function isImpersonating(Request $request): bool
    {
        return $request->session()->has(self::SESSION_ACTOR_ID);
    }

    /**
     * Absolute expiry instant stored in the session, if any.
     */
    public function expiresAt(Request $request): ?Carbon
    {
        $expiresAtUnix = $request->session()->get(self::SESSION_EXPIRES_AT);

        if (! is_int($expiresAtUnix) && ! is_numeric($expiresAtUnix)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $expiresAtUnix);
    }

    /**
     * Whether the time box has passed (still impersonating but expired).
     */
    public function isExpired(Request $request): bool
    {
        if (! $this->isImpersonating($request)) {
            return false;
        }

        $expiresAt = $this->expiresAt($request);

        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt->isPast();
    }

    /**
     * Actor (instance admin) restored when impersonation ends.
     */
    public function actor(Request $request): ?User
    {
        $actorId = $request->session()->get(self::SESSION_ACTOR_ID);

        if (! is_int($actorId) && ! is_numeric($actorId)) {
            return null;
        }

        return User::query()->find((int) $actorId);
    }

    /**
     * Begin impersonating $target; Auth::user() becomes the target.
     *
     * @side-effect Writes session keys, switches the web guard, regenerates the session id.
     */
    public function start(Request $request, User $actor, User $target): void
    {
        $ttlMinutes = max(0, (int) config('signaculum.impersonation_ttl_minutes'));
        $expiresAt = $ttlMinutes > 0
            ? now()->addMinutes($ttlMinutes)
            : null;

        $request->session()->put([
            self::SESSION_ACTOR_ID => $actor->id,
            self::SESSION_TARGET_ID => $target->id,
            self::SESSION_EXPIRES_AT => $expiresAt?->getTimestamp(),
        ]);

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
    }

    /**
     * End impersonation and restore the actor when possible.
     *
     * @return User|null The restored actor, or null when the actor no longer exists.
     *
     * @side-effect Clears session keys; logs the actor back in or logs out.
     */
    public function stop(Request $request): ?User
    {
        $actor = $this->actor($request);
        $request->session()->forget([
            self::SESSION_ACTOR_ID,
            self::SESSION_TARGET_ID,
            self::SESSION_EXPIRES_AT,
        ]);

        if ($actor instanceof User && $actor->is_active) {
            Auth::guard('web')->login($actor);
            $request->session()->regenerate();

            return $actor;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return null;
    }

    /**
     * Payload shared with the Inertia UI (banner), or null when not impersonating.
     *
     * @return array{actor_name: string, expires_at: string|null}|null
     */
    public function sharedState(Request $request): ?array
    {
        if (! $this->isImpersonating($request)) {
            return null;
        }

        $actor = $this->actor($request);
        $expiresAt = $this->expiresAt($request);

        return [
            'actor_name' => $actor instanceof User ? $actor->name : __('Instance admin'),
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
    }
}
