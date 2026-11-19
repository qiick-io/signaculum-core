<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fleet principal kinds. Signaculum v1 only creates `user` rows; `service` is reserved.
 */
enum PrincipalTypeEnum: string
{
    case User = 'user';
    case Service = 'service';
}
