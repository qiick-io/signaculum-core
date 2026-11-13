<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated payload for creating or updating an OIDC application.
 */
final readonly class ApplicationData
{
    /**
     * @param  list<string>  $redirectUris
     */
    public function __construct(
        public string $name,
        public array $redirectUris,
        public bool $isConfidential,
        public bool $isFirstParty,
    ) {}
}
