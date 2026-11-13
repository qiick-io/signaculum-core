<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles a member may hold in an organisation (`orgs[].roles` on the wire).
 *
 * A member may hold several at once (Decision 2026-11-13). Every organisation
 * must keep at least one `owner`.
 */
enum OrganizationRoleEnum: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
    case Billing = 'billing';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
