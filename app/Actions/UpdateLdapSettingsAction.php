<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\LdapSettingsData;
use App\Enums\ActivityEventEnum;
use App\Models\User;
use App\Services\LdapSettingsService;

/**
 * Persists instance LDAP connector settings (#77). Bind password is encrypted at rest
 * and never written to the activity log.
 *
 * Side effects: settings row write; activity log with keys only.
 */
final class UpdateLdapSettingsAction
{
    public function __construct(
        private readonly LdapSettingsService $ldapSettings,
    ) {}

    public function execute(LdapSettingsData $settingsData, ?User $actor = null): void
    {
        $this->ldapSettings->save($settingsData);

        $activity = activity()
            ->useLog('settings')
            ->event(ActivityEventEnum::SettingsUpdated->value)
            ->withProperties([
                'section' => 'ldap',
                'keys' => [
                    'enabled',
                    'hosts',
                    'port',
                    'encryption',
                    'bind_dn',
                    'base_dn',
                    'user_filter',
                    'attribute_mail',
                    'attribute_given_name',
                    'attribute_family_name',
                    'attribute_display_name',
                    'attribute_username',
                    'attribute_guid',
                    'sync_interval_minutes',
                    // Intentionally omit bind_password from logged keys when unchanged empty.
                    ...(is_string($settingsData->bindPassword) && $settingsData->bindPassword !== ''
                        ? ['bind_password']
                        : []),
                ],
            ]);

        if ($actor !== null) {
            $activity->causedBy($actor);
        } else {
            $activity->causedByAnonymous();
        }

        $activity->log('Instance settings updated');
    }
}
