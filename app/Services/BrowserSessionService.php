<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\BrowserSessionData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Reads the user's signed-in browsers from the database session store.
 *
 * ponytail: works only with `SESSION_DRIVER=database` (the default); other drivers
 * cannot list sessions per user, and the page then shows only the current one.
 * The browser and platform come from a few user-agent patterns, not a full parser.
 */
final class BrowserSessionService
{
    /**
     * @return list<BrowserSessionData> most recently active first
     */
    public function sessionsFor(User $user, string $currentSessionId): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return array_values(DB::connection(config('session.connection'))
            ->table((string) config('session.table'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $session): BrowserSessionData => new BrowserSessionData(
                publicId: $this->publicId((string) $session->id),
                ipAddress: $session->ip_address,
                browser: $this->browser((string) $session->user_agent),
                platform: $this->platform((string) $session->user_agent),
                isCurrentDevice: hash_equals($currentSessionId, (string) $session->id),
                lastActiveAt: (int) $session->last_activity,
            ))
            ->all());
    }

    /**
     * The session id behind a public id, limited to this user's sessions.
     */
    public function sessionIdFor(User $user, string $publicId): ?string
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        $sessionIds = DB::connection(config('session.connection'))
            ->table((string) config('session.table'))
            ->where('user_id', $user->getKey())
            ->pluck('id');

        foreach ($sessionIds as $sessionId) {
            if (hash_equals($this->publicId((string) $sessionId), $publicId)) {
                return (string) $sessionId;
            }
        }

        return null;
    }

    public function publicId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function browser(string $userAgent): string
    {
        $browserPatterns = [
            'Edge' => '/Edg\//',
            'Opera' => '/OPR\//',
            'Firefox' => '/Firefox\//',
            'Chrome' => '/Chrome\//',
            'Safari' => '/Safari\//',
        ];

        foreach ($browserPatterns as $browserName => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $browserName;
            }
        }

        return 'Unknown';
    }

    private function platform(string $userAgent): string
    {
        $platformPatterns = [
            'iOS' => '/iPhone|iPad|iPod/',
            'Android' => '/Android/',
            'macOS' => '/Macintosh|Mac OS X/',
            'Windows' => '/Windows/',
            'Linux' => '/Linux/',
        ];

        foreach ($platformPatterns as $platformName => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $platformName;
            }
        }

        return 'Unknown';
    }
}
