<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Validated payload for creating an organisation invitation.
 */
readonly class OrganizationInvitationData
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public string $email,
        public array $roles,
    ) {}
}
