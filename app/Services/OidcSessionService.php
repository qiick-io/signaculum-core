<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OidcSession;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * Per-browser OIDC `sid` (OIDC Front-/Back-Channel Logout §2).
 *
 * Stable for the life of the Laravel session, regenerated on every interactive login
 * so a stolen session cookie cannot keep the previous `sid` after re-authentication.
 */
final class OidcSessionService
{
    public const string SESSION_KEY = 'oidc.session_id';

    /**
     * Ensure the current browser has a `sid`, creating one when needed.
     */
    public function currentOidcSessionId(User $user): string
    {
        if (! request()->hasSession()) {
            return $this->newOidcSessionId();
        }

        $existing = request()->session()->get(self::SESSION_KEY);

        if (is_string($existing) && $existing !== '') {
            $this->persistBinding($user, request()->session()->getId(), $existing);

            return $existing;
        }

        return $this->regenerate($user);
    }

    /**
     * Issue a fresh `sid` for the current browser (call on login).
     */
    public function regenerate(User $user): string
    {
        $oidcSessionId = $this->newOidcSessionId();

        if (! request()->hasSession()) {
            return $oidcSessionId;
        }

        $laravelSessionId = request()->session()->getId();

        OidcSession::query()
            ->where('laravel_session_id', $laravelSessionId)
            ->whereNull('ended_at')
            ->update(['ended_at' => Date::now()]);

        request()->session()->put(self::SESSION_KEY, $oidcSessionId);
        $this->persistBinding($user, $laravelSessionId, $oidcSessionId);

        return $oidcSessionId;
    }

    public function oidcSessionIdForLaravelSession(string $laravelSessionId): ?string
    {
        return OidcSession::query()
            ->where('laravel_session_id', $laravelSessionId)
            ->whereNull('ended_at')
            ->value('oidc_session_id');
    }

    public function markEnded(string $oidcSessionId): void
    {
        OidcSession::query()
            ->where('oidc_session_id', $oidcSessionId)
            ->whereNull('ended_at')
            ->update(['ended_at' => Date::now()]);
    }

    private function persistBinding(User $user, string $laravelSessionId, string $oidcSessionId): void
    {
        // Laravel may rotate the session id after login; keep the row keyed by `sid`.
        $existingBySid = OidcSession::query()->where('oidc_session_id', $oidcSessionId)->first();

        if ($existingBySid !== null) {
            $existingBySid->forceFill([
                'user_id' => $user->getKey(),
                'laravel_session_id' => $laravelSessionId,
                'ended_at' => null,
            ])->save();

            return;
        }

        OidcSession::query()->updateOrCreate(
            ['laravel_session_id' => $laravelSessionId],
            [
                'user_id' => $user->getKey(),
                'oidc_session_id' => $oidcSessionId,
                'ended_at' => null,
            ],
        );
    }

    private function newOidcSessionId(): string
    {
        return Str::lower((string) Str::ulid());
    }
}
