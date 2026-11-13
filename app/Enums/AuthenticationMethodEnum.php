<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * RFC 8176 Authentication Method Reference values Signaculum records and emits as `amr`.
 *
 * `MultipleFactors` is a meta-value added when two or more concrete factors were used
 * in the same authentication.
 */
enum AuthenticationMethodEnum: string
{
    case Password = 'pwd';
    case OneTimePassword = 'otp';
    case HardwareKey = 'hwk';
    case SoftwareKey = 'swk';
    case MultipleFactors = 'mfa';

    /**
     * Concrete factors (everything except the `mfa` meta-value).
     */
    public function isFactor(): bool
    {
        return $this !== self::MultipleFactors;
    }
}
