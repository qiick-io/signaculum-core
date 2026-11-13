# Changelog

## [Unreleased]

### Added

- Named rate limiters for `/oauth/token`, `/oauth/authorize`, end-session, password-reset email, and (reserved) organisation invitations.
- Admin → Users: deactivate/reactivate (login refused, tokens revoked, back-channel logout), reset two-factor, grant/revoke instance admin (never the last one).
- Activity log (`spatie/laravel-activitylog`): admin page, auth/consent/revoke/settings events, and `activity_retention_days` with a daily prune.
- Instance setting `registration_enabled` (default off): self-registration and the login "Sign up" link stay closed until an instance admin turns them on (Settings → Authentication & security). qiick Cloud can enable registration on its hosted instance.
- `signaculum:install` and `signaculum:upgrade` for a self-hosted tree.
- `GET /health/live` and `GET /health/ready`.
- Multi-stage `Dockerfile`, `docker-bake.hcl` and a Compose quick start.

### Upgrade notes

- `composer create-project` no longer migrates. Run `php artisan signaculum:install` (or `php artisan migrate --force` on a tree that was already installed before this change).
- Later updates: put the new code in place, install dependencies, then `php artisan signaculum:upgrade`. Do not run `create-project` again.
- New dependency: `spatie/laravel-activitylog`. Run `composer update` and migrate so the `activity_log` table exists.
