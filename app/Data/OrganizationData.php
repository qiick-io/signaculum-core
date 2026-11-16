<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated payload for creating or updating an organisation.
 */
final readonly class OrganizationData
{
    public function __construct(
        public string $name,
        public string $slug,
        public bool $isMfaRequired = false,
    ) {}
}
