<?php

declare(strict_types=1);

namespace App\Enums;

use App\Events\InstanceAdminDemotedEvent;

/**
 * How a user stopped being an instance admin (see {@see InstanceAdminDemotedEvent}).
 *
 * - `role_revoked`: the super-admin role was taken away directly (console or CLI).
 * - `group_changed`: a group edit or deletion removed the role they held through it.
 * - `deactivated`: the account was deactivated (console, Admin API or LDAP sync).
 * - `deleted`: the account was deleted; unlike the other paths this revokes their API keys.
 */
enum InstanceAdminDemotionReasonEnum: string
{
    case RoleRevoked = 'role_revoked';
    case GroupChanged = 'group_changed';
    case Deactivated = 'deactivated';
    case Deleted = 'deleted';
}
