<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Activity-log event names offered by the activity log filter: Spatie's model events plus the
 * manual events written for auth, second-factor changes, consent, token revocation, secret
 * rotation, organisation MFA, settings and API keys.
 *
 * Every case needs an `activityLog.events.<value>` label in each locale file
 * (tests/Feature/Admin/ActivityEventLabelsTest.php).
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
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorConfirmed = 'two_factor_confirmed';
    case TwoFactorDisabled = 'two_factor_disabled';
    case RecoveryCodesRegenerated = 'recovery_codes_regenerated';
    case PasskeyRegistered = 'passkey_registered';
    case PasskeyDeleted = 'passkey_deleted';
    case SettingsUpdated = 'settings_updated';
    case ConsentGranted = 'consent_granted';
    case TokenRevoked = 'token_revoked';
    case SecretRotated = 'secret_rotated';
    case Rotated = 'rotated';
    case Revoked = 'revoked';
    case OrganizationMfaRequirementUpdated = 'organization_mfa_requirement_updated';
    case OrganizationMemberTokensRevoked = 'organization_member_tokens_revoked';
    case OrganizationMemberBackchannelLogoutDispatched = 'organization_member_backchannel_logout_dispatched';
    case UserImpersonationStarted = 'user_impersonation_started';
    case UserImpersonationStopped = 'user_impersonation_stopped';
    case UserImpersonationExpired = 'user_impersonation_expired';
    case LdapSyncCompleted = 'ldap_sync_completed';
    case LdapSyncConflict = 'ldap_sync_conflict';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
