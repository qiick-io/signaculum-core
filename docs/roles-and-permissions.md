# Roles and permissions

Signaculum follows app-ui `docs/roles-and-permissions.md` with one declared divergence.

## Package

- `spatie/laravel-permission` with `teams => false` in `config/permission.php` (Spatie teams, C29, are a separate decision: #223).
- [`qiick/laravel-access`](https://github.com/qiick-io/laravel-access) (`^0.1@beta`, `config/access.php`) owns the effective-permission resolver, the one `Gate::before`, the `permission:` route middleware, the two-factor middleware, the FormRequest helper, the group tables and the `principals` table. Signaculum binds its role names, its two-factor rule and its refusals (`App\Services\Access\*Service`).
- API actors (Admin API keys, Passport bearer tokens: `ACCESS_API_GUARDS=api,api-key`) never get the super-admin pass; model policies decide for them.
- Direct `hasPermission()` calls outside Policies are listed in `qiick-standards.json` `access.direct_checks` and checked by `DirectPermissionChecksTest`.
- Organisation membership roles stay JSON on `organization_user.roles` and feed the OIDC `orgs` claim. They are **not** Spatie team roles (decision 2026-11-19).

## Built-in roles

| Role          | Behaviour                                                                    |
| ------------- | ---------------------------------------------------------------------------- |
| `super-admin` | Everything, through qiick/laravel-access' `Gate::before` (web sessions only) |
| `admin`       | Every `PermissionEnum` permission                                            |
| `reader`      | Every `can-show-*` permission                                                |

## Commands

- `php artisan signaculum:sync-permissions` — creates missing permissions, ensures built-in roles and their defaults, writes `resources/js/enums/permission-enum.ts`. Run from `signaculum:install` and `signaculum:upgrade`.
- `php artisan signaculum:create-user` — first production admin (asks email/password/role; `--no-interaction` with options).
- `php artisan signaculum:make-admin {email}` — grant/revoke the `super-admin` role on an existing user.

## Initial super-admin

`config/super_admin.php` reads `INITIAL_SUPER_ADMIN_*`. `CreateSuperAdminSeeder` runs only in `local` / `testing` with a non-empty password. `.env.example` sets `INITIAL_SUPER_ADMIN_PASSWORD=password` for local convenience.

## User groups

Instance-level groups on qiick/laravel-access' tables (`user_groups` with `public_id`, `is_instance`, soft deletes; `user_group_user` with an optional `expires_at`; `user_group_role`). Every Signaculum group is an instance group, the only kind whose super-admin role counts (C30). Effective permissions = user roles ∪ group roles (`Qiick\Access\EffectivePermissionResolver`). Admin UI under `/admin/groups`.

## Principals

`principals` is qiick/laravel-access' table with `access.principals.organisation_required = true` and `instance_types = ['instance_service']` (read when the migration runs: never change them afterwards). Signaculum adds the restrict foreign key to `organizations`. Principals are created and deactivated through `PrincipalDirectory`; deactivating one revokes its Admin API keys (`RevokeDeactivatedPrincipalApiKeysListener`). Reactivating a user reactivates only the membership principals deactivated as `admin` by the user deactivation.

## Former `is_instance_admin`

Before beta every `is_instance_admin = true` user was migrated to `super-admin`, then the column was dropped. UI capability checks use `useCan()` / shared `auth.permissions`; the users list still exposes `is_instance_admin` as a derived “holds super-admin” flag for the grant/revoke toggle.
