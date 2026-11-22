<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\DeactivateUserAction;
use App\Actions\ReactivateUserAction;
use App\Data\LdapSettingsData;
use App\Data\LdapSyncResultData;
use App\Enums\ActivityEventEnum;
use App\Enums\WebhookEventEnum;
use App\Ldap\DirectoryUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LdapRecord\Models\Model as LdapModel;
use LdapRecord\Query\Model\Builder;
use Throwable;

/**
 * Read-only LDAP → local user sync (#77).
 *
 * Creates/updates users linked by `ldap_guid`, deactivates directory-missing LDAP
 * users, and logs email conflicts when a local-only account already owns the address.
 * Active Directory accounts with ACCOUNTDISABLE stay deactivated even when they still
 * match the filter (#187). Synced users get no local password (bind-on-login only).
 * Never writes to the directory.
 *
 * Side effects: user rows, activity log, optional deactivation (tokens/sessions), webhooks.
 */
final class LdapUserSyncService
{
    public function __construct(
        private readonly LdapSettingsService $ldapSettings,
        private readonly LdapConnectionService $ldapConnection,
        private readonly DeactivateUserAction $deactivateUserAction,
        private readonly ReactivateUserAction $reactivateUserAction,
        private readonly WebhookDispatcherService $webhookDispatcher,
    ) {}

    /**
     * @throws \RuntimeException when LDAP is misconfigured
     * @throws Throwable when the directory call fails
     */
    public function sync(): LdapSyncResultData
    {
        $settingsData = $this->ldapSettings->connectionSettings();

        if (! $settingsData->isEnabled) {
            return new LdapSyncResultData(0, 0, 0, 0, 0);
        }

        $this->ldapConnection->applyFromSettings($settingsData);

        $directoryUsers = $this->queryDirectoryUsers($settingsData);
        $seenGuids = [];
        $created = 0;
        $updated = 0;
        $conflicts = 0;
        $skipped = 0;

        foreach ($directoryUsers as $directoryUser) {
            $guid = $this->resolveGuid($directoryUser, $settingsData->attributeGuid);

            if ($guid === null) {
                $skipped++;

                continue;
            }

            $seenGuids[] = $guid;
            $email = $this->firstAttribute($directoryUser, $settingsData->attributeMail);

            if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;

                continue;
            }

            $email = Str::lower($email);
            $directoryUsername = $this->firstAttribute($directoryUser, $settingsData->attributeUsername);
            $firstName = $this->firstAttribute($directoryUser, $settingsData->attributeGivenName)
                ?? $this->firstAttribute($directoryUser, $settingsData->attributeDisplayName)
                ?? $this->firstAttribute($directoryUser, $settingsData->attributeUsername)
                ?? 'User';
            $lastName = $this->firstAttribute($directoryUser, $settingsData->attributeFamilyName);
            $distinguishedName = $directoryUser->getDn();
            $isDirectoryAccountDisabled = $directoryUser instanceof DirectoryUser
                ? $directoryUser->isDirectoryAccountDisabled()
                : $this->isAccountControlDisabled($directoryUser);

            // Include soft-deleted rows: a recreated local user would violate unique ldap_guid.
            // Soft-deleted LDAP accounts are never restored — the directory owns lifecycle (2026-11-22).
            $linkedUser = User::withTrashed()->where('ldap_guid', $guid)->first();

            if ($linkedUser !== null) {
                if ($linkedUser->trashed()) {
                    Log::warning('LDAP sync skipped soft-deleted user; directory owns lifecycle', [
                        'user_subject' => $linkedUser->subject,
                        'ldap_guid' => $guid,
                    ]);
                    $skipped++;

                    continue;
                }

                if ($this->updateLinkedUser(
                    $linkedUser,
                    $firstName,
                    $lastName,
                    $email,
                    $directoryUsername,
                    $distinguishedName,
                    $isDirectoryAccountDisabled,
                )) {
                    $updated++;
                } else {
                    $conflicts++;
                }

                continue;
            }

            // Soft-deleted emails stay taken until erasure (#220 / C17).
            $emailOwner = User::withTrashed()->where('email', $email)->first();

            if ($emailOwner !== null) {
                if ($emailOwner->ldap_guid !== null) {
                    $skipped++;

                    continue;
                }

                $this->logConflict($emailOwner, $guid, $email);
                $conflicts++;

                continue;
            }

            $this->createLinkedUser(
                $firstName,
                $lastName,
                $email,
                $guid,
                $directoryUsername,
                $distinguishedName,
                $isDirectoryAccountDisabled,
            );
            $created++;
        }

        $deactivated = $this->deactivateMissing($seenGuids);

        $result = new LdapSyncResultData($created, $updated, $deactivated, $conflicts, $skipped);

        $this->ldapSettings->markSyncedAt(now()->toIso8601String());
        $this->logSyncRun($result);

        return $result;
    }

    /**
     * @return Collection<int, LdapModel>
     */
    private function queryDirectoryUsers(LdapSettingsData $settingsData)
    {
        // Do not project attributes: DirectoryEmulator attribute names are case-sensitive
        // and a narrow select drops givenName/sn values created under different casing.
        $query = DirectoryUser::query();

        $filter = trim($settingsData->userFilter);

        if ($filter !== '') {
            $this->applyUserFilter($query, $filter);
        }

        return $query->get();
    }

    /**
     * Prefer a simple equality filter so DirectoryEmulator tests work; fall back to rawFilter.
     *
     * @param  Builder  $query
     */
    private function applyUserFilter($query, string $filter): void
    {
        if (preg_match('/^\(\s*([a-zA-Z0-9_-]+)\s*=\s*(.+?)\s*\)$/', $filter, $matches) === 1) {
            $query->where($matches[1], '=', $matches[2]);

            return;
        }

        $query->rawFilter($filter);
    }

    private function resolveGuid(LdapModel $directoryUser, string $attributeGuid): ?string
    {
        try {
            $converted = $directoryUser->getConvertedGuid();

            if (is_string($converted) && $converted !== '') {
                return Str::lower($converted);
            }
        } catch (Throwable) {
            // Fall through to configured / alternate attributes.
        }

        foreach ([$attributeGuid, 'objectGUID', 'entryUUID', 'objectguid', 'entryuuid'] as $attribute) {
            $value = $this->firstAttribute($directoryUser, $attribute);

            if ($value !== null && $value !== '') {
                return Str::lower($value);
            }
        }

        return null;
    }

    private function firstAttribute(LdapModel $directoryUser, string $attribute): ?string
    {
        if ($attribute === '') {
            return null;
        }

        foreach (array_unique([$attribute, strtolower($attribute)]) as $candidate) {
            $value = $directoryUser->getFirstAttribute($candidate);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * @return bool false when an email conflict blocked the update
     */
    private function updateLinkedUser(
        User $user,
        string $firstName,
        ?string $lastName,
        string $email,
        ?string $directoryUsername,
        ?string $distinguishedName,
        bool $isDirectoryAccountDisabled,
    ): bool {
        $emailOwner = User::withTrashed()
            ->where('email', $email)
            ->whereKeyNot($user->getKey())
            ->first();

        if ($emailOwner !== null) {
            $this->logConflict($emailOwner, (string) $user->ldap_guid, $email);

            return false;
        }

        $attributes = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'ldap_dn' => $distinguishedName,
            'password' => null,
        ];

        $resolvedUsername = $this->resolvedUsernameForSync($directoryUsername, $user->getKey());

        if ($resolvedUsername !== null) {
            $attributes['username'] = $resolvedUsername;
        }

        $user->forceFill($attributes)->save();

        if ($isDirectoryAccountDisabled) {
            if ($user->is_active) {
                try {
                    $this->deactivateUserAction->execute($user);
                } catch (ValidationException) {
                    Log::warning('LDAP sync could not deactivate disabled directory user', [
                        'user_subject' => $user->subject,
                    ]);
                }
            }
        } elseif (! $user->is_active) {
            $this->reactivateUserAction->execute($user);
        }

        $this->webhookDispatcher->dispatch(WebhookEventEnum::UserUpdated, [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ]);

        return true;
    }

    private function createLinkedUser(
        string $firstName,
        ?string $lastName,
        string $email,
        string $guid,
        ?string $directoryUsername,
        ?string $distinguishedName,
        bool $isDirectoryAccountDisabled,
    ): void {
        $attributes = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => null,
            'is_active' => ! $isDirectoryAccountDisabled,
            'email_verified_at' => now(),
            'ldap_guid' => $guid,
            'ldap_dn' => $distinguishedName,
        ];

        $resolvedUsername = $this->resolvedUsernameForSync($directoryUsername, null);

        if ($resolvedUsername !== null) {
            $attributes['username'] = $resolvedUsername;
        }

        $user = (new User)->forceFill($attributes);
        $user->save();

        $this->webhookDispatcher->dispatch(WebhookEventEnum::UserCreated, [
            'id' => $user->subject,
            'email' => $user->email,
            'name' => $user->name,
        ]);
    }

    /**
     * Normalize and validate a directory username; skip (and log) on invalid shape or uniqueness conflict.
     */
    private function resolvedUsernameForSync(?string $directoryUsername, ?int $ignoreUserId): ?string
    {
        if ($directoryUsername === null || $directoryUsername === '') {
            return null;
        }

        $normalized = User::normalizeUsername($directoryUsername);

        if ($normalized === null || ! User::isValidUsername($normalized)) {
            Log::warning('LDAP sync skipped username: invalid shape', [
                'username' => $directoryUsername,
            ]);

            return null;
        }

        $conflictQuery = User::query()->where('username', $normalized);

        if ($ignoreUserId !== null) {
            $conflictQuery->whereKeyNot($ignoreUserId);
        }

        if ($conflictQuery->exists()) {
            Log::warning('LDAP sync skipped username: already taken', [
                'username' => $normalized,
            ]);

            return null;
        }

        return $normalized;
    }

    /**
     * ACCOUNTDISABLE (0x2) on userAccountControl when the model is not a DirectoryUser.
     */
    private function isAccountControlDisabled(LdapModel $directoryUser): bool
    {
        $control = $this->firstAttribute($directoryUser, 'userAccountControl');

        if ($control === null) {
            return false;
        }

        return ((int) $control & 0x2) === 0x2;
    }

    /**
     * @param  list<string>  $seenGuids
     */
    private function deactivateMissing(array $seenGuids): int
    {
        $query = User::query()
            ->whereNotNull('ldap_guid')
            ->where('is_active', true);

        if ($seenGuids !== []) {
            $query->whereNotIn('ldap_guid', $seenGuids);
        }

        $deactivated = 0;

        foreach ($query->get() as $user) {
            try {
                $this->deactivateUserAction->execute($user);
                $deactivated++;
            } catch (ValidationException) {
                // Keep the last instance admin active; count as skipped conflict.
                Log::warning('LDAP sync could not deactivate user', [
                    'user_subject' => $user->subject,
                ]);
            }
        }

        return $deactivated;
    }

    private function logConflict(User $localUser, string $guid, string $email): void
    {
        activity()
            ->causedByAnonymous()
            ->useLog('ldap')
            ->event(ActivityEventEnum::LdapSyncConflict->value)
            ->withProperties([
                'email' => $email,
                'ldap_guid' => $guid,
                'local_user_subject' => $localUser->subject,
            ])
            ->log('LDAP sync skipped: local account already owns this email');
    }

    private function logSyncRun(LdapSyncResultData $result): void
    {
        activity()
            ->causedByAnonymous()
            ->useLog('ldap')
            ->event(ActivityEventEnum::LdapSyncCompleted->value)
            ->withProperties([
                'created' => $result->created,
                'updated' => $result->updated,
                'deactivated' => $result->deactivated,
                'conflicts' => $result->conflicts,
                'skipped' => $result->skipped,
            ])
            ->log('LDAP user sync completed');
    }
}
