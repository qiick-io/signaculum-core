# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0-beta.1] - 2026-11-13

First tagged beta of Signaculum: self-hosted OpenID Connect IdP for the qiick suite (Passport 13 + in-house OIDC layer, admin UI, organisations, distribution).

### Fixed

- Remember-me recaller login no longer records a spurious `pwd` authentication method for `amr` (only interactive password login does).
- RFC 9207 `iss` middleware scoped to authorize routes so unrelated redirects are untouched.

### Added

- RFC 7662 token introspection at `POST /oauth/introspect` (confidential clients), advertised as `introspection_endpoint` in discovery; inactive / revoked / expired tokens return `{ "active": false }`.
- OAuth 2.0 `client_credentials` for confidential applications that opt in (Admin → Applications): machine access tokens without `id_token`; discovery `grant_types_supported` includes `client_credentials`.
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
- RP-initiated logout (`end_session_endpoint`) and back-channel logout (`logout_token`, per-client fan-out); `sid` on the `id_token`.
- Named rate limiters for `/oauth/token`, `/oauth/authorize`, end-session, introspection, password-reset email, and organisation invitations.
- Admin → Users: deactivate/reactivate (login refused, tokens revoked, back-channel logout), reset two-factor, grant/revoke instance admin (never the last one).
- Admin → Applications: list and drawer (redirect URIs, post-logout URIs, back-channel URI, confidential/public, first-party, client credentials opt-in); secret shown once; regenerate and delete with confirmation.
- Activity log (`spatie/laravel-activitylog`): admin page, auth/consent/revoke/settings events, and `activity_retention_days` with a daily prune.
- Instance setting `registration_enabled` (default off): self-registration and the login "Sign up" link stay closed until an instance admin turns them on (Settings → Authentication & security).
- Instance settings (general, localization, authentication, appearance), account area (sessions, connected apps), security headers and branded error pages.
- Passport 13 with discovery, JWKS, PKCE S256, nonce, RS256 `id_token` and userinfo.
- `signaculum:install` and `signaculum:upgrade` for a self-hosted tree.
- `GET /health/live` and `GET /health/ready`.
- Multi-stage `Dockerfile`, `docker-bake.hcl` and a Compose quick start.
- Operator docs: install, upgrade, clients, organisations, key rotation and backup; README release readiness; required-checks list for branch protection.

### Upgrade notes

- **First tag.** There is no previous SemVer upgrade path. Fresh installs: `composer create-project qiick/signaculum-core` then `php artisan signaculum:install`.
- `composer create-project` does **not** migrate. Always finish with `signaculum:install` (or `php artisan migrate --force` on a tree that was already installed from `develop` before this layout).
- Later updates: put the new code in place, install dependencies (`composer install`, `npm ci`, `npm run build`), then `php artisan signaculum:upgrade`. Do **not** run `create-project` again. Optional: `signaculum:upgrade --sync-upstream=v1.0.0-beta.1` (try `--dry-run` first).
- New dependency: `spatie/laravel-activitylog`. Run `composer update` / `composer install` and migrate so the `activity_log` table exists.
- Back up `APP_KEY`, `storage/oauth-*.key` and the database (including `signing_keys`) before moving hosts — see [docs/key-rotation-and-backup.md](./docs/key-rotation-and-backup.md).
- Beta: wire contracts may still change before 1.0.0; pin clients to this tag and read [docs/oidc-profile.md](./docs/oidc-profile.md).

[Unreleased]: https://github.com/qiick-io/signaculum-core/compare/v1.0.0-beta.1...HEAD
[1.0.0-beta.1]: https://github.com/qiick-io/signaculum-core/releases/tag/v1.0.0-beta.1
