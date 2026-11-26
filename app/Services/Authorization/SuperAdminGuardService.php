<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\RoleEnum;
use App\Models\User;
use App\Models\UserGroup;
use Illuminate\Database\Eloquent\Builder;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Access\EffectivePermissionResolver;
use Spatie\Permission\Models\Role;

/**
 * Keeps the super-admin role out of reach of delegated administrators.
 *
 * `can-edit-users` / `can-edit-groups` holders may manage ordinary accounts, but
 * granting super-admin (directly or through a group), or editing / deleting an
 * existing super-admin, requires the acting user to be a super-admin. Without
 * this guard any user manager could promote themselves past `Gate::before`.
 *
 * Copied from externa-core (app-ui roles-and-permissions / #206) and extended for
 * active last-super-admin counting (direct ∪ group).
 */
class SuperAdminGuardService
{
    public function __construct(
        private EffectivePermissionResolver $permissionResolver,
        private ActorResolver $actorResolver,
    ) {}

    /**
     * Whether the actor may grant, revoke, or manage super-admin access. Never for an API actor
     * (an Admin API key, an OAuth grant), whichever admin it acts as: a scoped credential carries
     * no super-admin authority, as the package's Gate::before already rules for permissions.
     */
    public function canManageSuperAdmins(?User $actor): bool
    {
        if ($actor === null || $this->actorResolver->current()->isApiActor()) {
            return false;
        }

        return $this->permissionResolver->isSuperAdmin($actor);
    }

    /**
     * Whether the actor is blocked from editing or deleting the target account.
     */
    public function isProtectedFrom(?User $actor, User $target): bool
    {
        return ! $this->canManageSuperAdmins($actor) && $this->permissionResolver->isSuperAdmin($target);
    }

    /**
     * Active users who hold super-admin directly or via a (non-trashed) group.
     *
     * @param  list<int>  $exceptUserIds
     */
    public function countActiveSuperAdmins(array $exceptUserIds = []): int
    {
        $isSuperAdminRole = fn (Builder $roleQuery) => $roleQuery->where('name', RoleEnum::SuperAdmin->value);

        return User::query()
            ->where('is_active', true)
            ->when($exceptUserIds !== [], fn (Builder $query) => $query->whereKeyNot($exceptUserIds))
            ->where(fn (Builder $userQuery) => $userQuery
                ->whereHas('roles', $isSuperAdminRole)
                ->orWhereHas('groups', fn (Builder $groupQuery) => $this->grantingSuperAdmin($groupQuery)))
            ->count();
    }

    /**
     * Whether `$user` is the sole active super-admin (direct or via group).
     */
    public function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->is_active || ! $this->permissionResolver->isSuperAdmin($user)) {
            return false;
        }

        return $this->countActiveSuperAdmins() === 1;
    }

    /**
     * Whether applying the next membership and roles on `$group` would leave zero active super-admins.
     *
     * @param  list<int>|null  $nextUserIds  null keeps the current members
     * @param  list<int>|null  $nextRoleIds  null keeps the current roles
     */
    public function wouldLeaveNoActiveSuperAdminAfterGroupUpdate(
        UserGroup $group,
        ?array $nextUserIds,
        ?array $nextRoleIds,
    ): bool {
        if ($this->countActiveSuperAdmins() === 0) {
            return false;
        }

        // sync() keeps a retained member's expires_at and attaches a new one without: after the
        // edit, the members granting the role are the listed ones minus those already expired.
        $expiredMemberIds = $group->users()
            ->whereNotNull('user_group_user.expires_at')
            ->where('user_group_user.expires_at', '<=', now())
            ->pluck('users.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $resolvedUserIds = array_values(array_diff(
            $nextUserIds ?? $group->users()->pluck('users.id')->map(fn ($id): int => (int) $id)->all(),
            $expiredMemberIds,
        ));
        $resolvedRoleIds = $nextRoleIds ?? $group->roles()->pluck('roles.id')->map(fn ($id): int => (int) $id)->all();
        // Only an instance group's super-admin role counts (the package's instanceRoleIdsFor()).
        $groupKeepsSuperAdmin = $group->is_instance && $this->hasSuperAdminRole($resolvedRoleIds);
        $isSuperAdminRole = fn (Builder $roleQuery) => $roleQuery->where('name', RoleEnum::SuperAdmin->value);

        $remaining = User::query()
            ->where('is_active', true)
            ->where(function (Builder $userQuery) use ($group, $resolvedUserIds, $groupKeepsSuperAdmin, $isSuperAdminRole): void {
                $userQuery
                    ->whereHas('roles', $isSuperAdminRole)
                    ->orWhereHas(
                        'groups',
                        fn (Builder $groupQuery) => $this->grantingSuperAdmin($groupQuery->whereKeyNot($group->id)),
                    );

                if ($groupKeepsSuperAdmin && $resolvedUserIds !== []) {
                    $userQuery->orWhereIn('id', $resolvedUserIds);
                }
            })
            ->count();

        return $remaining === 0;
    }

    /**
     * Whether removing these groups (or stripping their roles) would leave zero active super-admins.
     *
     * @param  array<int, mixed>  $groupIds
     */
    public function wouldRemoveLastActiveSuperAdminViaGroups(array $groupIds): bool
    {
        $groupIds = $this->integerIds($groupIds);

        if ($groupIds === [] || ! $this->hasSuperAdminGroup($groupIds)) {
            return false;
        }

        $memberIds = User::query()
            ->where('is_active', true)
            ->whereHas('groups', fn (Builder $groupQuery) => $this->grantingSuperAdmin($groupQuery->whereIn('user_groups.id', $groupIds)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($memberIds === []) {
            return false;
        }

        // Members who only get super-admin through these groups (no other path).
        $solelyViaTheseGroups = 0;

        foreach ($memberIds as $memberId) {
            $member = User::query()->find($memberId);

            if ($member === null || ! $this->permissionResolver->isSuperAdmin($member)) {
                continue;
            }

            $hasDirect = $member->roles()->where('name', RoleEnum::SuperAdmin->value)->exists();
            $hasOtherGroup = $this->grantingSuperAdmin($member->groups()->whereNotIn('user_groups.id', $groupIds)->getQuery())->exists();

            if (! $hasDirect && ! $hasOtherGroup) {
                $solelyViaTheseGroups++;
            }
        }

        if ($solelyViaTheseGroups === 0) {
            return false;
        }

        return $this->countActiveSuperAdmins() <= $solelyViaTheseGroups;
    }

    /**
     * Groups through which a member is a super-admin, by the same rules as qiick/laravel-access'
     * resolver (EloquentGroupRoleSource::instanceRoleIdsFor()): a live instance group holding
     * the role, with a membership that has not expired. Soft-deleted groups are left out by the
     * model's scope. For a query on the user's `groups` relation (the pivot is joined).
     *
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $groupQuery
     * @return TBuilder
     */
    private function grantingSuperAdmin(Builder $groupQuery): Builder
    {
        return $groupQuery
            ->where('user_groups.is_instance', true)
            ->where(fn (Builder $membershipQuery) => $this->unexpiredMembership($membershipQuery))
            ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', RoleEnum::SuperAdmin->value));
    }

    /**
     * A membership with no expiry, or one still in the future (the pivot `user_group_user`).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $membershipQuery
     * @return Builder<TModel>
     */
    private function unexpiredMembership(Builder $membershipQuery): Builder
    {
        return $membershipQuery
            ->whereNull('user_group_user.expires_at')
            ->orWhere('user_group_user.expires_at', '>', now());
    }

    /**
     * Whether any of the given role ids is the super-admin role.
     *
     * @param  array<int, mixed>  $roleIds
     */
    public function hasSuperAdminRole(array $roleIds): bool
    {
        return Role::query()
            ->whereIn('id', $this->integerIds($roleIds))
            ->where('name', RoleEnum::SuperAdmin->value)
            ->exists();
    }

    /**
     * Whether any of the given role names is the super-admin role.
     *
     * @param  array<int, string>  $roleNames
     */
    public function hasSuperAdminRoleName(array $roleNames): bool
    {
        return in_array(RoleEnum::SuperAdmin->value, $roleNames, true);
    }

    /**
     * Whether any of the given groups (including soft-deleted ones) grants the super-admin role to its members.
     *
     * @param  array<int, mixed>  $groupIds
     */
    public function hasSuperAdminGroup(array $groupIds): bool
    {
        return UserGroup::withTrashed()
            ->whereIn('id', $this->integerIds($groupIds))
            ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', RoleEnum::SuperAdmin->value))
            ->exists();
    }

    /**
     * Whether any of the given users is a super-admin, directly or via a group.
     *
     * @param  array<int, mixed>  $userIds
     */
    public function hasSuperAdminUser(array $userIds): bool
    {
        return User::query()
            ->whereIn('id', $this->integerIds($userIds))
            ->where(fn (Builder $userQuery) => $userQuery
                ->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->where('name', RoleEnum::SuperAdmin->value))
                ->orWhereHas('groups', fn (Builder $groupQuery) => $this->grantingSuperAdmin($groupQuery)))
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function integerIds(array $ids): array
    {
        return array_values(array_map('intval', array_filter($ids, 'is_numeric')));
    }
}
