<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * RFC 8176 Authentication Method Reference values Signaculum records at login
 * and may emit as the `amr` claim in the id_token.
 */
enum AuthenticationMethodEnum: string
{
    case Password = 'pwd';
    case OneTimePassword = 'otp';
    case HardwareKey = 'hwk';
    case SoftwareKey = 'swk';
    case MultipleFactors = 'mfa';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $method): string => $method->value,
            self::cases(),
        );
    }
}
