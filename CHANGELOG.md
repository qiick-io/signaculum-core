# Changelog

## [Unreleased]

### Fixed

- Remember-me recaller login no longer records a spurious `pwd` authentication method for `amr` (only interactive password login does).

### Added

- Instance-admin editable email templates (verification, password reset, organisation invitation) with en/it/de defaults, preview and fallback to packaged copy.
- RFC 7009 token revocation at `/oauth/revoke` (`revocation_endpoint`), with the same client auth methods as the token endpoint.
- OIDC scope `qiick.orgs` and claim `orgs: [{ id, slug, roles }]` on `id_token` and userinfo (consent copy; discovery `scopes_supported` / `claims_supported`).
- `acr` on the `id_token` only when requested (`acr_values` or `claims`): `urn:qiick:loa:pwd` or `urn:qiick:loa:mfa`, with discovery `acr_values_supported`.
- Account data export (JSON) from Settings → Profile: profile, organisations, sessions and connected apps (never passwords or 2FA secrets).
- Organisation invitations (email, hashed token, expiry, resend/revoke) and Members / Invitations / Settings view tabs.
- OIDC `max_age` / `prompt=login` on `/oauth/authorize`: re-authenticate when session `auth_time` is too old (or `prompt=login`), without back-channel fan-out and keeping the same `sid`; `prompt=none` + stale `max_age` returns `login_required` with `iss` and leaves the session intact; always emit `auth_time` in the `id_token` when `max_age` was sent.
- Brute-force login lockout: configurable failure threshold and duration, activity log on lock, admin unlock on Users.
- Organisations and memberships (`organizations`, `organization_user`): multi-role JSON (`owner` / `admin` / `member` / `billing`), never zero owners, CRUD UI under Organisations.
- RFC 9207 `iss` on every authorization response (success and error) and discovery flag `authorization_response_iss_parameter_supported: true`.
- `amr` on every `id_token` (RFC 8176): recorded at login as `pwd` / `otp` / `hwk` / `swk`, plus `mfa` when two factors were used, and carried with the authorization code.
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
