<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\TokenFamilyRootEnum;

/**
 * A token family: the access and refresh tokens issued from one authorization code or
 * one device code, including every refresh of them (see TokenFamilyService).
 */
final readonly class TokenFamilyData
{
    public function __construct(
        public TokenFamilyRootEnum $root,
        public string $rootId,
    ) {}
}
