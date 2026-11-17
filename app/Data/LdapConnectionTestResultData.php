<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Outcome of an LDAP bind/connectivity probe (#77). Never carries the bind password.
 */
final readonly class LdapConnectionTestResultData
{
    public function __construct(
        public bool $isSuccessful,
        public string $message,
    ) {}
}
