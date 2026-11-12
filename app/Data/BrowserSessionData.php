<?php

declare(strict_types=1);

namespace App\Data;

/**
 * One signed-in browser of the current user, as shown on Settings → Sessions.
 *
 * `publicId` is a SHA-256 of the session id: the raw id is the session cookie value
 * and must never reach the page.
 */
final readonly class BrowserSessionData
{
    public function __construct(
        public string $publicId,
        public ?string $ipAddress,
        public string $browser,
        public string $platform,
        public bool $isCurrentDevice,
        public int $lastActiveAt,
    ) {}
}
