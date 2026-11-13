<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Activity-log event names offered by the activity log filter: Spatie's model events plus the
 * manual events written for auth, consent, token revocation, secret rotation and settings.
 */
enum ActivityEventEnum: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
    case Login = 'login';
    case Logout = 'logout';
    case Failed = 'failed';
    case Locked = 'locked';
    case SettingsUpdated = 'settings_updated';
    case ConsentGranted = 'consent_granted';
    case TokenRevoked = 'token_revoked';
    case SecretRotated = 'secret_rotated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
