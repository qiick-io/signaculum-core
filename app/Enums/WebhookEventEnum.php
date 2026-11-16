<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Outbound webhook event types an instance admin may subscribe an endpoint to.
 *
 * Wire values are stable: document them in docs/webhooks.md and never rename without
 * a major release note.
 */
enum WebhookEventEnum: string
{
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserDeleted = 'user.deleted';
    case UserLocked = 'user.locked';
    case UserSignedIn = 'user.signed_in';
    case OrganizationMembershipChanged = 'organization.membership_changed';
    case ApplicationCreated = 'application.created';
    case ApplicationDeleted = 'application.deleted';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
