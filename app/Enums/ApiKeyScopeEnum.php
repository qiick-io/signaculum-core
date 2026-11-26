<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\ApiKeyScopeVocabularyService;

/**
 * Capability scopes for Signaculum Admin REST API keys, in the shared qiick vocabulary
 * `^[a-z][a-z0-9-]*:(read|write)$` where `write` implies `read`
 * (qiick/laravel-api-keys `ScopeSet`, through {@see ApiKeyScopeVocabularyService}).
 *
 * There is deliberately no scope for API keys themselves: key management is a session-only
 * action, so no key can mint or widen a key. Wire values are stable; document them in
 * docs/admin-api.md.
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
