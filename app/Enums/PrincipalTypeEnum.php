<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fleet principal kinds.
 *
 * - `user`: one human principal per organisation membership (`organisation_id` required).
 * - `service`: reserved for org-scoped service accounts.
 * - `instance_service`: singleton Admin REST API owner; the only type allowed a NULL
 *   `organisation_id` (decision 2026-11-22 / #229).
 */
enum PrincipalTypeEnum: string
{
    case User = 'user';
    case Service = 'service';
    case InstanceService = 'instance_service';
}
