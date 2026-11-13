<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Inputs for minting one back-channel `logout_token` (OIDC Back-Channel Logout 1.0 §2.4).
 *
 * At least one of `subject` and `oidcSessionId` must be set so the RP can match sessions.
 */
final readonly class LogoutTokenRequestData
{
    public function __construct(
        public string $clientId,
        public ?string $subject,
        public ?string $oidcSessionId,
    ) {}
}
