<?php

declare(strict_types=1);

namespace App\Data;

/**
 * An application (OIDC client) the user has authorized and that still holds a valid
 * access or refresh token.
 */
final readonly class ConnectedApplicationData
{
    /**
     * @param  list<string>  $scopes  every scope granted across the client's live tokens
     */
    public function __construct(
        public string $clientId,
        public string $name,
        public array $scopes,
        public int $firstAuthorizedAt,
        public int $lastAuthorizedAt,
    ) {}
}
