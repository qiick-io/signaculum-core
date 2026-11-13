<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Transactional email templates editable by instance admins (#53).
 */
enum EmailTemplateTypeEnum: string
{
    case Verification = 'verification';
    case Reset = 'reset';
    case Invitation = 'invitation';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Placeholders the admin may use in subject and body for this type.
     *
     * @return list<string>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::Verification => ['app_name', 'user_name', 'url'],
            self::Reset => ['app_name', 'user_name', 'url', 'expire_minutes'],
            self::Invitation => ['app_name', 'organization_name', 'url'],
        };
    }
}
