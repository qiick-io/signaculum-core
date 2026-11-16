<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Capability scopes for Signaculum Admin REST API keys.
 *
 * Wire values are stable; document them in docs/admin-api.md.
 */
enum ApiKeyScopeEnum: string
{
    case UsersRead = 'users:read';
    case UsersWrite = 'users:write';
    case OrganizationsRead = 'organizations:read';
    case OrganizationsWrite = 'organizations:write';
    case ApplicationsRead = 'applications:read';
    case ApplicationsWrite = 'applications:write';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
