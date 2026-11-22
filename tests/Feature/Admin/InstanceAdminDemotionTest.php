<?php

declare(strict_types=1);

use App\Actions\CreateApiKeyAction;
use App\Actions\DeactivateUserAction;
use App\Actions\DeleteUserAction;
use App\Actions\SetInstanceAdminAction;
use App\Data\ApiKeyData;
use App\Enums\ApiKeyScopeEnum;
use App\Enums\InstanceAdminDemotionReasonEnum;
use App\Enums\RoleEnum;
use App\Events\InstanceAdminDemotedEvent;
use App\Listeners\NotifyAdminsOfDemotedApiKeyCreatorListener;
use App\Models\ApiKey;
use App\Models\Role;
use App\Models\User;
use App\Models\UserGroup;
use App\Notifications\ApiKeyCreatorDemotedNotification;
use App\Services\InstanceAdminDemotionService;
use App\Services\LdapUserSyncService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LdapRecord\Laravel\Testing\DirectoryEmulator;

mutates(InstanceAdminDemotionService::class, NotifyAdminsOfDemotedApiKeyCreatorListener::class, ApiKeyCreatorDemotedNotification::class);

/**
 * When an Admin API key's creator stops being an instance admin, on any path, the other active
 * instance admins hear about every key of theirs that still works, and the key is not revoked
 * (decision 2026-11-22). A deletion is the exception: it revokes the keys, and the mail says so.
 */
beforeEach(function (): void {
    syncPermissions();
    $this->freezeSecond();

    // Performs the demotions, and stays an admin throughout.
    $this->actingSuperAdmin = User::factory()->instanceAdmin()->create(['locale' => 'en']);
    $this->creator = User::factory()->instanceAdmin()->create(['first_name' => 'Ada', 'last_name' => 'Keys', 'password' => 'password']);
});

/**
 * @return list<ApiKey>
 */
function keysCreatedBy(User $creator, int $count = 1): array
{
    return array_map(
        static fn (int $index): ApiKey => app(CreateApiKeyAction::class)->execute(
            $creator,
            new ApiKeyData("Sync key {$index}", [ApiKeyScopeEnum::UsersRead->value], expiresAt: apiKeyExpiry()),
        )->apiKey,
        range(1, $count),
    );
}

/**
 * A user who is an instance admin only through a group holding the admin role.
 *
 * @return array{user: User, group: UserGroup}
 */
function groupOnlyAdmin(): array
{
    $user = User::factory()->create();
    $group = UserGroup::factory()->create();
    $group->users()->attach($user);
    $group->roles()->attach(Role::query()->where('name', RoleEnum::Admin->value)->sole());

    return ['user' => $user, 'group' => $group];
}

function assertDemotedOnceFor(User $user, InstanceAdminDemotionReasonEnum $reason): void
{
    Event::assertDispatchedTimes(InstanceAdminDemotedEvent::class, 1);
    Event::assertDispatched(
        InstanceAdminDemotedEvent::class,
        static fn (InstanceAdminDemotedEvent $event): bool => $event->user->is($user) && $event->reason === $reason,
    );
}

describe('every demotion path raises the event once', function (): void {
    beforeEach(function (): void {
        Event::fake([InstanceAdminDemotedEvent::class]);
    });

    it('raises it when the super-admin role is revoked in the console', function (): void {
        $this->actingAs($this->actingSuperAdmin)
            ->put(route('admin.users.instance-admin.update', $this->creator), ['is_instance_admin' => false])
            ->assertRedirect(route('admin.users.index'));

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::RoleRevoked);
    });

    it('raises it when the super-admin role is revoked from the command line', function (): void {
        $this->artisan('signaculum:make-admin', ['email' => $this->creator->email, '--revoke' => true])->assertSuccessful();

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::RoleRevoked);
    });

    it('raises it when a group edit removes the member', function (): void {
        ['user' => $groupAdmin, 'group' => $group] = groupOnlyAdmin();

        $this->actingAs($this->actingSuperAdmin)
            ->put(route('admin.groups.update', $group), ['name' => $group->name, 'user_ids' => []])
            ->assertRedirect(route('admin.groups.index'));

        assertDemotedOnceFor($groupAdmin, InstanceAdminDemotionReasonEnum::GroupChanged);
    });

    it('raises it when a group edit removes the admin role', function (): void {
        ['user' => $groupAdmin, 'group' => $group] = groupOnlyAdmin();

        $this->actingAs($this->actingSuperAdmin)
            ->put(route('admin.groups.update', $group), ['name' => $group->name, 'role_ids' => []])
            ->assertRedirect(route('admin.groups.index'));

        assertDemotedOnceFor($groupAdmin, InstanceAdminDemotionReasonEnum::GroupChanged);
    });

    it('raises it when the group is deleted', function (): void {
        ['user' => $groupAdmin, 'group' => $group] = groupOnlyAdmin();

        $this->actingAs($this->actingSuperAdmin)
            ->delete(route('admin.groups.destroy', $group))
            ->assertRedirect(route('admin.groups.index'));

        assertDemotedOnceFor($groupAdmin, InstanceAdminDemotionReasonEnum::GroupChanged);
    });

    it('raises it when the account is deactivated in the console', function (): void {
        $this->actingAs($this->actingSuperAdmin)
            ->put(route('admin.users.activation.update', $this->creator), ['is_active' => false])
            ->assertRedirect(route('admin.users.index'));

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::Deactivated);
    });

    it('raises it when the account is deactivated through the Admin API', function (): void {
        ['plain' => $plainTextToken] = adminApiKey([ApiKeyScopeEnum::UsersWrite->value]);
        Event::fake([InstanceAdminDemotedEvent::class]);

        $this->withToken($plainTextToken)
            ->postJson(route('api.v1.users.deactivate', $this->creator->subject))
            ->assertOk();

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::Deactivated);
    });

    it('raises it when the LDAP sync deactivates an account that left the directory', function (): void {
        fakeLdapDirectory();
        enableLdapSettings();
        $this->creator->forceFill(['ldap_guid' => Str::lower((string) Str::uuid())])->save();

        app(LdapUserSyncService::class)->sync();
        DirectoryEmulator::tearDown();

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::Deactivated);
    });

    it('raises it once, as a deletion, when the account deletes itself', function (): void {
        $this->actingAs($this->creator)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertRedirect(route('home'));

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::Deleted);
    });

    it('does not raise it while another path still makes the user an admin', function (): void {
        ['user' => $groupAdmin, 'group' => $group] = groupOnlyAdmin();
        $groupAdmin->assignRole(RoleEnum::SuperAdmin->value);

        app(SetInstanceAdminAction::class)->execute($groupAdmin, false, $this->actingSuperAdmin);

        Event::assertNotDispatched(InstanceAdminDemotedEvent::class);

        $this->actingAs($this->actingSuperAdmin)->delete(route('admin.groups.destroy', $group));

        assertDemotedOnceFor($groupAdmin, InstanceAdminDemotionReasonEnum::GroupChanged);
    });

    it('does not raise it again when a demoted user is touched on another path', function (): void {
        app(SetInstanceAdminAction::class)->execute($this->creator, false, $this->actingSuperAdmin);
        app(DeactivateUserAction::class)->execute($this->creator);
        app(DeleteUserAction::class)->execute($this->creator);

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::RoleRevoked);
    });

    it('sees a change the permission cache does not know about yet', function (): void {
        app(InstanceAdminDemotionService::class)->track(
            [$this->creator->getKey()],
            InstanceAdminDemotionReasonEnum::RoleRevoked,
            fn () => $this->creator->roles()->detach(),
        );

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::RoleRevoked);
    });

    it('does not raise it for a user who was never an admin, or when the change fails', function (): void {
        app(DeactivateUserAction::class)->execute(User::factory()->create());

        expect(fn () => app(InstanceAdminDemotionService::class)->track(
            [$this->creator->getKey()],
            InstanceAdminDemotionReasonEnum::RoleRevoked,
            static function (): never {
                throw new RuntimeException('change failed');
            },
        ))->toThrow(RuntimeException::class);

        Event::assertNotDispatched(InstanceAdminDemotedEvent::class);

        // The failed change left the service ready for the next one.
        app(DeactivateUserAction::class)->execute($this->creator);

        assertDemotedOnceFor($this->creator, InstanceAdminDemotionReasonEnum::Deactivated);
    });
});

describe('the other instance admins are told about the keys', function (): void {
    beforeEach(function (): void {
        Notification::fake();
    });

    it('mails every other active admin once per key that still works, and keeps the keys', function (): void {
        $otherAdmin = permissionAdmin();
        $inactiveAdmin = User::factory()->instanceAdmin()->create(['is_active' => false]);
        $regularUser = User::factory()->create();
        $workingKeys = keysCreatedBy($this->creator, 2);
        [$revokedKey, $expiredKey] = keysCreatedBy($this->creator, 2);
        $revokedKey->forceFill(['revoked_at' => now()])->save();
        $expiredKey->forceFill(['expires_at' => now()])->save();
        keysCreatedBy($otherAdmin);

        app(SetInstanceAdminAction::class)->execute($this->creator, false, $this->actingSuperAdmin);

        foreach ([$this->actingSuperAdmin, $otherAdmin] as $admin) {
            Notification::assertSentToTimes($admin, ApiKeyCreatorDemotedNotification::class, 2);

            foreach ($workingKeys as $workingKey) {
                Notification::assertSentTo(
                    $admin,
                    ApiKeyCreatorDemotedNotification::class,
                    fn (ApiKeyCreatorDemotedNotification $notification): bool => $notification->apiKey->is($workingKey)
                        && ! $notification->isRevoked
                        && $notification->creatorLabel === "Ada Keys ({$this->creator->email})",
                );
            }
        }

        Notification::assertNotSentTo([$this->creator, $inactiveAdmin, $regularUser], ApiKeyCreatorDemotedNotification::class);

        foreach ($workingKeys as $workingKey) {
            expect($workingKey->fresh()?->isActive())->toBeTrue();
        }
    });

    it('names the key and its creator, and links to the console to revoke it', function (): void {
        [$apiKey] = keysCreatedBy($this->creator);

        app(DeactivateUserAction::class)->execute($this->creator);

        Notification::assertSentTo(
            $this->actingSuperAdmin,
            ApiKeyCreatorDemotedNotification::class,
            function (ApiKeyCreatorDemotedNotification $notification) use ($apiKey): bool {
                $mail = $notification->toMail($this->actingSuperAdmin);

                expect($mail->subject)->toBe('API key Sync key 1: its creator is no longer an instance admin')
                    ->and($mail->introLines)->toBe([
                        "The API key Sync key 1 ({$apiKey->key_prefix}) on Signaculum was created by Ada Keys ({$this->creator->email}), who is no longer an instance admin.",
                        'The key still works. Revoke it in the console if nobody should use it any more.',
                    ])
                    ->and($mail->actionUrl)->toBe(route('admin.api-keys.index'))
                    ->and($notification->via($this->actingSuperAdmin))->toBe(['mail']);

                return true;
            },
        );

        expect($apiKey->fresh()?->isRevoked())->toBeFalse();
    });

    it('tells the admins which keys a deletion revoked', function (): void {
        [$apiKey] = keysCreatedBy($this->creator);
        [$alreadyRevokedKey] = keysCreatedBy($this->creator);
        $alreadyRevokedKey->forceFill(['revoked_at' => now()->subMinute()])->save();

        app(DeleteUserAction::class)->execute($this->creator);

        Notification::assertSentToTimes($this->actingSuperAdmin, ApiKeyCreatorDemotedNotification::class, 1);
        Notification::assertSentTo(
            $this->actingSuperAdmin,
            ApiKeyCreatorDemotedNotification::class,
            function (ApiKeyCreatorDemotedNotification $notification) use ($apiKey): bool {
                $mail = $notification->toMail($this->actingSuperAdmin);

                expect($notification->apiKey->is($apiKey))->toBeTrue()
                    ->and($notification->isRevoked)->toBeTrue()
                    ->and($mail->subject)->toBe('API key Sync key 1 was revoked')
                    ->and($mail->introLines[0])->toBe(
                        "The API key Sync key 1 ({$apiKey->key_prefix}) on Signaculum was revoked because its creator Ada Keys ({$this->creator->email}) was deleted.",
                    );

                return true;
            },
        );

        expect($apiKey->fresh()?->isRevoked())->toBeTrue();
    });

    it('mails in the recipient locale', function (): void {
        $italianAdmin = User::factory()->instanceAdmin()->create(['locale' => 'it']);
        keysCreatedBy($this->creator);

        app(DeactivateUserAction::class)->execute($this->creator);

        Notification::assertSentTo(
            $italianAdmin,
            ApiKeyCreatorDemotedNotification::class,
            static fn (ApiKeyCreatorDemotedNotification $notification): bool => $notification->toMail($italianAdmin)->subject
                === 'Chiave API Sync key 1: il suo creatore non è più admin di istanza',
        );
    });

    it('mails in the instance locale when the recipient has none', function (): void {
        config(['app.locale' => 'de']);
        $adminWithoutLocale = User::factory()->instanceAdmin()->create(['locale' => null]);
        keysCreatedBy($this->creator);

        app(DeactivateUserAction::class)->execute($this->creator);

        Notification::assertSentTo(
            $adminWithoutLocale,
            ApiKeyCreatorDemotedNotification::class,
            static fn (ApiKeyCreatorDemotedNotification $notification): bool => $notification->toMail($adminWithoutLocale)->subject
                === 'API-Schlüssel Sync key 1: Ersteller ist kein Instanz-Admin mehr',
        );
    });

    it('stays silent when the demoted admin created no working key', function (): void {
        [$revokedKey] = keysCreatedBy($this->creator);
        $revokedKey->forceFill(['revoked_at' => now()])->save();

        app(DeactivateUserAction::class)->execute($this->creator);

        Notification::assertNothingSent();
    });
});
