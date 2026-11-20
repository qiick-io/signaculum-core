<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Instance-admin capabilities. Synced to the database and `resources/js/enums/permission-enum.ts`
 * by `signaculum:sync-permissions`. Values follow `can-<verb>-<resource>` (app-ui roles-and-permissions).
 *
 * Mapping from the former `users.is_instance_admin` flag (decision 2026-11-19 / #206):
 *
 * | Former check | Permission |
 * | --- | --- |
 * | UserPolicy viewAny/view | can-show-users |
 * | UserPolicy create | can-create-users |
 * | UserPolicy update (+ activation, lockout, 2FA reset, instance-admin toggle) | can-edit-users |
 * | UserPolicy impersonate | can-impersonate-users |
 * | ApplicationPolicy * | can-show/create/edit/delete-applications |
 * | OrganizationPolicy instance-admin bypass | can-manage-organizations |
 * | SettingPolicy update (instance settings, LDAP, webhooks, email templates, branding, security) | can-manage-settings |
 * | ApiKeyPolicy * (+ AuthenticateApiKeyMiddleware owner gate) | can-show-api-keys / can-manage-api-keys |
 * | ActivityPolicy viewAny | can-show-activity-logs |
 * | User groups (new) | can-show/create/edit/delete/restore/force-delete-groups |
 */
enum PermissionEnum: string
{
    case CanShowUsers = 'can-show-users';
    case CanCreateUsers = 'can-create-users';
    case CanEditUsers = 'can-edit-users';
    case CanDeleteUsers = 'can-delete-users';
    case CanImpersonateUsers = 'can-impersonate-users';

    case CanShowGroups = 'can-show-groups';
    case CanCreateGroups = 'can-create-groups';
    case CanEditGroups = 'can-edit-groups';
    case CanDeleteGroups = 'can-delete-groups';
    case CanRestoreGroups = 'can-restore-groups';
    case CanForceDeleteGroups = 'can-force-delete-groups';

    case CanShowApplications = 'can-show-applications';
    case CanCreateApplications = 'can-create-applications';
    case CanEditApplications = 'can-edit-applications';
    case CanDeleteApplications = 'can-delete-applications';

    case CanManageOrganizations = 'can-manage-organizations';

    case CanManageSettings = 'can-manage-settings';

    case CanShowApiKeys = 'can-show-api-keys';
    case CanManageApiKeys = 'can-manage-api-keys';

    case CanShowActivityLogs = 'can-show-activity-logs';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The resource a permission belongs to (`can-create-users` → `users`).
     */
    public function resource(): string
    {
        return (string) preg_replace('/^can-[a-z]+-/', '', $this->value);
    }

    /**
     * Whether this is a read-only “show” permission (default set for the reader role).
     */
    public function isShowPermission(): bool
    {
        return str_starts_with($this->value, 'can-show-');
    }
}
