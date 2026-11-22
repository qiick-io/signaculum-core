<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Counters from one read-only LDAP sync run (#77).
 */
final readonly class LdapSyncResultData
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $deactivated,
        public int $conflicts,
        public int $skipped,
    ) {}
}
