# Roles and permissions

Signaculum follows app-ui `docs/roles-and-permissions.md` with one declared divergence.

## Package

- `spatie/laravel-permission` with `teams => false` in `config/permission.php`.
- Organisation membership roles stay JSON on `organization_user.roles` and feed the OIDC `orgs` claim. They are **not** Spatie team roles (decision 2026-11-19).

## Built-in roles

| Role          | Behaviour                                                                   |
| ------------- | --------------------------------------------------------------------------- |
| `super-admin` | Everything, through `Gate::before` via `EffectivePermissionResolverService` |
| `admin`       | Every `PermissionEnum` permission                                           |
| `reader`      | Every `can-show-*` permission                                               |

## Commands

- `php artisan signaculum:sync-permissions` — creates missing permissions, ensures built-in roles and their defaults, writes `resources/js/enums/permission-enum.ts`. Run from `signaculum:install` and `signaculum:upgrade`.
- `php artisan signaculum:create-user` — first production admin (asks email/password/role; `--no-interaction` with options).
- `php artisan signaculum:make-admin {email}` — grant/revoke the `super-admin` role on an existing user.

## Initial super-admin

`config/super_admin.php` reads `INITIAL_SUPER_ADMIN_*`. `CreateSuperAdminSeeder` runs only in `local` / `testing` with a non-empty password. `.env.example` sets `INITIAL_SUPER_ADMIN_PASSWORD=password` for local convenience.

## User groups

Instance-level groups (soft deletes, pivots to users and roles). Effective permissions = user roles ∪ group roles (`EffectivePermissionResolverService`). Admin UI under `/admin/groups`.

## Former `is_instance_admin`

Before beta every `is_instance_admin = true` user was migrated to `super-admin`, then the column was dropped. UI capability checks use `useCan()` / shared `auth.permissions`; the users list still exposes `is_instance_admin` as a derived “holds super-admin” flag for the grant/revoke toggle.
