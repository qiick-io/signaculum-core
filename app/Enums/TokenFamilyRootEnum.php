<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Database\Eloquent\Model;
use Laravel\Passport\Passport;

/**
 * The grant a token family descends from. The backing value is the column of
 * `oauth_access_tokens` that holds the id of the family's root row.
 */
enum TokenFamilyRootEnum: string
{
    case AuthCode = 'auth_code_id';
    case DeviceCode = 'device_code_id';

    /**
     * A fresh model of the root's table: its row is the family's lock.
     */
    public function rootModel(): Model
    {
        return match ($this) {
            self::AuthCode => Passport::authCode(),
            self::DeviceCode => Passport::deviceCode(),
        };
    }
}
